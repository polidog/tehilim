<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * Translates Schema AST models into TableDef structs used by drivers + diff.
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
