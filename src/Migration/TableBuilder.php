<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

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
            $out[] = self::fromModel($model);
        }
        foreach (self::joinTables($schema) as $jt) {
            $out[] = $jt;
        }

        return $out;
    }

    public static function fromModel(Model $model): TableDef
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
        );
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
}
