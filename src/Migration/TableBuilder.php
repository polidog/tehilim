<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use Polidog\Tehilim\Client\Relation;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;
use Polidog\Tehilim\Schema\RelationResolver;
use Throwable;

/**
 * Translates Schema AST models into TableDef structs used by drivers + diff.
 *
 * Also synthesizes join tables for implicit many-to-many relations
 * (`_AToB` shape).
 */
final class TableBuilder
{
    /** @return list<TableDef> */
    public static function fromSchema(Schema $schema): array
    {
        $out = [];
        foreach ($schema->models as $model) {
            $out[] = self::fromModel($model, self::foreignKeysForModel($model, $schema));
        }
        foreach (self::joinTables($schema) as $jt) {
            $out[] = $jt;
        }

        // Order tables so a referenced table is created before the table that
        // references it (required by MySQL/PostgreSQL; harmless on SQLite).
        return self::topologicalSort($out);
    }

    /**
     * @param list<ForeignKeyDef> $foreignKeys
     */
    public static function fromModel(Model $model, array $foreignKeys = []): TableDef
    {
        $columns = [];
        foreach ($model->scalarFields() as $field) {
            $columns[] = new ColumnDef(
                name: $field->columnName(),
                phpType: self::phpType($field),
                nullable: $field->nullable,
                autoIncrement: self::isAutoIncrement($field),
                default: self::defaultValue($field),
            );
        }

        $pk = $model->primaryKey();
        $unique = [];
        foreach ($model->scalarFields() as $field) {
            if ($field->hasAttribute('unique')) {
                $unique[] = $field->columnName();
            }
        }

        return new TableDef(
            name: $model->tableName(),
            columns: $columns,
            primaryKey: $pk?->columnName(),
            uniqueColumns: $unique,
            compositePrimaryKey: $model->compositePrimaryKey(),
            compositeUniqueGroups: $model->compositeUniqueGroups(),
            foreignKeys: $foreignKeys,
        );
    }

    /**
     * Foreign keys for a model's belongsTo relations (the side that holds the
     * FK column). hasMany/hasOne and M2M sides hold no FK here.
     *
     * @return list<ForeignKeyDef>
     */
    public static function foreignKeysForModel(Model $model, Schema $schema): array
    {
        $resolver = new RelationResolver($schema);
        $fks = [];
        foreach ($model->relationFields() as $field) {
            try {
                $rel = $resolver->resolve($model, $field);
            } catch (Throwable) {
                continue;
            }
            if ($rel->kind !== Relation::BELONGS_TO) {
                continue;
            }
            if (count($rel->localFields) !== 1 || count($rel->foreignFields) !== 1) {
                continue; // composite-key relations unsupported in v1
            }
            $target = $schema->model($rel->target);
            if ($target === null) {
                continue;
            }
            $fks[] = new ForeignKeyDef(
                $rel->localFields[0],
                $target->tableName(),
                $rel->foreignFields[0],
            );
        }

        return $fks;
    }

    /** @return list<TableDef> */
    public static function joinTables(Schema $schema): array
    {
        $resolver = new RelationResolver($schema);
        $seen = [];
        $out = [];
        foreach ($schema->models as $model) {
            foreach ($model->relationFields() as $field) {
                try {
                    $rel = $resolver->resolve($model, $field);
                } catch (Throwable) {
                    continue;
                }
                if (!$rel->isManyToMany() || $rel->joinTable === null) {
                    continue;
                }
                if (isset($seen[$rel->joinTable])) {
                    continue;
                }
                $seen[$rel->joinTable] = true;

                $target = $schema->model($rel->target);
                if ($target === null) {
                    continue;
                }

                [$firstModel, $secondModel] = $model->name < $target->name
                    ? [$model, $target]
                    : [$target, $model];

                $aPk = $firstModel->primaryKey();
                $bPk = $secondModel->primaryKey();
                if ($aPk === null || $bPk === null) {
                    continue;
                }

                $out[] = new TableDef(
                    name: $rel->joinTable,
                    columns: [
                        new ColumnDef('A', self::phpType($aPk), nullable: false),
                        new ColumnDef('B', self::phpType($bPk), nullable: false),
                    ],
                    primaryKey: null,
                    uniqueColumns: [],
                    compositePrimaryKey: ['A', 'B'],
                    compositeUniqueGroups: [],
                    foreignKeys: [
                        new ForeignKeyDef('A', $firstModel->tableName(), $aPk->columnName()),
                        new ForeignKeyDef('B', $secondModel->tableName(), $bPk->columnName()),
                    ],
                );
            }
        }

        return $out;
    }

    public static function phpType(Field $field): string
    {
        return match ($field->type->name) {
            'Int' => 'int',
            'BigInt' => 'BigInt',
            'Float', 'Decimal' => 'float',
            'Boolean' => 'bool',
            'String' => 'string',
            'DateTime' => 'DateTime',
            'Json' => 'json',
            'Bytes' => 'bytes',
            default => 'string',
        };
    }

    public static function isAutoIncrement(Field $field): bool
    {
        $default = $field->attribute('default');
        if ($default === null) {
            return false;
        }
        $val = $default->args[0] ?? null;

        return $val instanceof Invocation && $val->name === 'autoincrement';
    }

    public static function defaultValue(Field $field): mixed
    {
        $default = $field->attribute('default');
        if ($default === null) {
            return null;
        }
        $val = $default->args[0] ?? null;
        if ($val instanceof Invocation) {
            return $val->name === 'now' ? 'now()' : null;
        }

        return $val;
    }

    /**
     * Order tables so each FK's referenced table appears before the table that
     * declares it (DFS post-order). Self-references are ignored; cycles don't
     * loop forever — a node already being visited is skipped, leaving the rest
     * in a best-effort order (SQLite tolerates it; true cycles would need ALTER).
     *
     * @param list<TableDef> $tables
     *
     * @return list<TableDef>
     */
    private static function topologicalSort(array $tables): array
    {
        $byName = [];
        foreach ($tables as $t) {
            $byName[$t->name] = $t;
        }

        $sorted = [];
        $state = [];
        foreach ($tables as $t) {
            self::visit($t, $byName, $state, $sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string,TableDef> $byName
     * @param array<string,string>   $state  name => 'visiting'|'done'
     * @param list<TableDef>         $sorted
     */
    private static function visit(TableDef $table, array $byName, array &$state, array &$sorted): void
    {
        $current = $state[$table->name] ?? null;
        if ($current === 'done' || $current === 'visiting') {
            return;
        }
        $state[$table->name] = 'visiting';
        foreach ($table->foreignKeys as $fk) {
            if ($fk->referencedTable === $table->name) {
                continue; // self-reference
            }
            $dep = $byName[$fk->referencedTable] ?? null;
            if ($dep !== null) {
                self::visit($dep, $byName, $state, $sorted);
            }
        }
        $state[$table->name] = 'done';
        $sorted[] = $table;
    }
}
