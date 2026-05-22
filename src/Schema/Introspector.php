<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Attribute;
use Polidog\Tehilim\Schema\Ast\BlockAttribute;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\FieldType;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * Reverse of code generation: reads a live database through a {@see Driver} and
 * reconstructs a Schema AST. Tables become models with scalar fields; primary
 * keys, uniques (single + composite), and auto-increment are recovered.
 *
 * Relations are inferred from foreign keys:
 *  - a FK column becomes a `belongsTo` (`@relation(fields, references)`), with
 *    the inverse `hasMany` (or `hasOne` when the FK column is unique) on the
 *    referenced model;
 *  - a `_XToY`-shaped join table (exactly two FK columns forming a composite
 *    PK) is folded into an implicit many-to-many on both referenced models, and
 *    the join table itself is dropped from the output.
 *
 * Note: tehilim's own `push` does not emit FK constraints, so relations are
 * only recovered from databases that actually declare them.
 */
final class Introspector
{
    /** Internal bookkeeping table that must never appear in the schema. */
    private const MIGRATIONS_TABLE = '_tehilim_migrations';

    public function __construct(private readonly Driver $driver) {}

    /**
     * Build a Schema from the live database. The datasource/generator blocks
     * are carried over from $template (typically the existing schema file) so a
     * `db pull` keeps the user's connection + output config intact.
     */
    public function introspect(?Schema $template = null): Schema
    {
        // Sort tables so output is deterministic — drivers don't ORDER BY
        // listTables(), so ordering would otherwise create noisy diffs.
        $tables = $this->driver->listTables();
        sort($tables);

        /** @var array<string,IntrospectedTable> $intro */
        $intro = [];
        foreach ($tables as $table) {
            if ($table === self::MIGRATIONS_TABLE) {
                continue;
            }
            $intro[$table] = $this->driver->introspectTable($table);
        }

        $joinTables = $this->detectJoinTables($intro);

        // Per-model used-name sets, seeded with scalar column names so relation
        // fields never collide with a real column (or with each other).
        $used = [];
        foreach ($intro as $name => $it) {
            if (isset($joinTables[$name])) {
                continue;
            }
            $used[$name] = [];
            foreach ($it->columns as $col) {
                $used[$name][$col->name] = true;
            }
        }

        /** @var array<string,list<Field>> $relations */
        $relations = [];
        $this->addManyToMany($intro, $joinTables, $used, $relations);
        $this->addForeignKeyRelations($intro, $joinTables, $used, $relations);

        $models = [];
        foreach ($intro as $name => $it) {
            if (isset($joinTables[$name])) {
                continue;
            }
            $models[] = $this->toModel($it, $relations[$name] ?? []);
        }

        return new Schema(
            $models,
            $template !== null ? $template->datasources : [],
            $template !== null ? $template->generators : [],
        );
    }

    /**
     * Identify implicit many-to-many join tables: exactly two columns, both
     * foreign keys, forming the composite primary key. Returns a map of join
     * table name => [referencedModelForColumn0, referencedModelForColumn1].
     *
     * @param array<string,IntrospectedTable> $intro
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function detectJoinTables(array $intro): array
    {
        $out = [];
        foreach ($intro as $name => $it) {
            // Only fold tables matching tehilim's implicit-M2M shape: named
            // `_XToY` with columns A and B forming the composite PK. Runtime
            // resolution (RelationResolver::buildManyToMany) hard-codes that
            // name and those columns, so a coincidentally-2-FK table with a
            // different shape must stay an explicit model — folding it would
            // drop it and emit relations that can't resolve at runtime.
            if (preg_match('/^_.+To.+$/', $name) !== 1) {
                continue;
            }
            if (count($it->columns) !== 2 || count($it->foreignKeys) !== 2) {
                continue;
            }
            $colNames = array_map(static fn ($c) => $c->name, $it->columns);
            sort($colNames);
            if ($colNames !== ['A', 'B']) {
                continue;
            }
            $pk = $it->compositePrimaryKey;
            if ($pk === null) {
                continue;
            }
            $pkSorted = $pk;
            sort($pkSorted);
            if ($pkSorted !== ['A', 'B']) {
                continue;
            }

            $refByColumn = [];
            foreach ($it->foreignKeys as $fk) {
                $refByColumn[$fk->column] = $fk->referencedTable;
            }
            $a = $refByColumn['A'] ?? null;
            $b = $refByColumn['B'] ?? null;
            if ($a === null || $b === null) {
                continue;
            }
            $out[$name] = [$a, $b];
        }

        return $out;
    }

    /**
     * @param array<string,IntrospectedTable>        $intro
     * @param array<string,array{0:string,1:string}> $joinTables
     * @param array<string,array<string,true>>       $used
     * @param array<string,list<Field>>              $relations
     */
    private function addManyToMany(array $intro, array $joinTables, array &$used, array &$relations): void
    {
        foreach ($joinTables as [$a, $b]) {
            if (!$this->isModel($a, $intro, $joinTables) || !$this->isModel($b, $intro, $joinTables)) {
                continue;
            }
            $relations[$a][] = $this->listField($this->alloc($used, $a, $this->plural($b)), $b);
            $relations[$b][] = $this->listField($this->alloc($used, $b, $this->plural($a)), $a);
        }
    }

    /**
     * @param array<string,IntrospectedTable>        $intro
     * @param array<string,array{0:string,1:string}> $joinTables
     * @param array<string,array<string,true>>       $used
     * @param array<string,list<Field>>              $relations
     */
    private function addForeignKeyRelations(array $intro, array $joinTables, array &$used, array &$relations): void
    {
        foreach ($intro as $name => $it) {
            if (isset($joinTables[$name])) {
                continue;
            }

            // Count FKs per referenced model: when a table holds 2+ FKs to the
            // same target (e.g. Post.authorId + Post.editorId -> User), the
            // inverse side is ambiguous because RelationResolver only matches
            // the first inverse @relation on the target. Emit belongsTo for
            // each, but skip the inverse for ambiguous pairs.
            $refCounts = [];
            foreach ($it->foreignKeys as $fk) {
                $refCounts[$fk->referencedTable] = ($refCounts[$fk->referencedTable] ?? 0) + 1;
            }

            foreach ($it->foreignKeys as $fk) {
                $ref = $fk->referencedTable;
                if (!$this->isModel($ref, $intro, $joinTables)) {
                    continue;
                }
                $col = $this->column($it, $fk->column);
                if ($col === null) {
                    continue;
                }

                // belongsTo on the FK-holding model.
                $relations[$name][] = $this->belongsToField(
                    $this->alloc($used, $name, lcfirst($ref)),
                    $ref,
                    $fk->column,
                    $fk->referencedColumn,
                    $col->nullable,
                );

                if (($refCounts[$ref] ?? 0) > 1) {
                    continue; // ambiguous inverse — belongsTo only
                }

                // Inverse on the referenced model: hasOne when the FK is unique
                // (1:1), otherwise hasMany.
                if ($col->unique || $col->primaryKey) {
                    $relations[$ref][] = $this->singleField($this->alloc($used, $ref, lcfirst($name)), $name);
                } else {
                    $relations[$ref][] = $this->listField($this->alloc($used, $ref, $this->plural($name)), $name);
                }
            }
        }
    }

    /**
     * @param array<string,IntrospectedTable>        $intro
     * @param array<string,array{0:string,1:string}> $joinTables
     */
    private function isModel(string $name, array $intro, array $joinTables): bool
    {
        return isset($intro[$name]) && !isset($joinTables[$name]) && $name !== self::MIGRATIONS_TABLE;
    }

    private function column(IntrospectedTable $table, string $name): ?IntrospectedColumn
    {
        foreach ($table->columns as $col) {
            if ($col->name === $name) {
                return $col;
            }
        }

        return null;
    }

    /**
     * Reserve a unique field name within a model, suffixing 2, 3, … on clash.
     *
     * @param array<string,array<string,true>> $used
     */
    private function alloc(array &$used, string $model, string $base): string
    {
        $name = $base;
        $i = 2;
        while (isset($used[$model][$name])) {
            $name = $base . $i++;
        }
        $used[$model][$name] = true;

        return $name;
    }

    private function plural(string $model): string
    {
        return lcfirst($model) . 's';
    }

    private function belongsToField(string $name, string $target, string $localColumn, string $referencedColumn, bool $nullable): Field
    {
        return new Field(
            name: $name,
            type: new FieldType($target),
            nullable: $nullable,
            list: false,
            attributes: [new Attribute('relation', [
                'fields' => [$localColumn],
                'references' => [$referencedColumn],
            ])],
        );
    }

    private function listField(string $name, string $target): Field
    {
        return new Field(name: $name, type: new FieldType($target), nullable: false, list: true);
    }

    private function singleField(string $name, string $target): Field
    {
        return new Field(name: $name, type: new FieldType($target), nullable: true, list: false);
    }

    /**
     * @param list<Field> $relationFields
     */
    private function toModel(IntrospectedTable $table, array $relationFields): Model
    {
        $fields = array_map($this->toField(...), $table->columns);
        $fields = [...$fields, ...$relationFields];

        $blockAttrs = [];
        if ($table->compositePrimaryKey !== null) {
            $blockAttrs[] = new BlockAttribute('id', [$table->compositePrimaryKey]);
        }
        foreach ($table->compositeUniques as $group) {
            $blockAttrs[] = new BlockAttribute('unique', [$group]);
        }

        return new Model($table->name, $fields, $blockAttrs);
    }

    private function toField(IntrospectedColumn $col): Field
    {
        $attributes = [];
        if ($col->primaryKey) {
            $attributes[] = new Attribute('id');
        }
        if ($col->unique && !$col->primaryKey) {
            $attributes[] = new Attribute('unique');
        }
        if ($col->autoIncrement) {
            $attributes[] = new Attribute('default', [new Invocation('autoincrement')]);
        }

        return new Field(
            name: $col->name,
            type: new FieldType($col->tehilimType),
            nullable: $col->nullable,
            list: false,
            attributes: $attributes,
        );
    }
}
