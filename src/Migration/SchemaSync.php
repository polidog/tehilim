<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use PDO;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * v0 "push": drops and re-creates tables to match the schema.
 * No history, no diffing — destructive by design. Intended for early dev.
 */
final class SchemaSync
{
    public function __construct(
        private readonly Driver $driver,
        private readonly Schema $schema,
    ) {}

    public function push(bool $drop = true): void
    {
        $tables = [];
        foreach ($this->schema->models as $model) {
            $tables[] = $this->buildTable($model);
        }

        $pdo = $this->driver->pdo();
        $pdo->beginTransaction();
        try {
            if ($drop) {
                foreach (array_reverse($tables) as $t) {
                    $this->runDdl($pdo, $this->driver->dropTableIfExistsSql($t->name));
                }
            }
            foreach ($tables as $t) {
                $this->runDdl($pdo, $this->driver->createTableSql($t));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function runDdl(PDO $pdo, string $sql): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    private function buildTable(Model $model): TableDef
    {
        $columns = [];
        foreach ($model->scalarFields() as $field) {
            $columns[] = new ColumnDef(
                name: $field->columnName(),
                phpType: $this->phpType($field),
                nullable: $field->nullable,
                autoIncrement: $this->isAutoIncrement($field),
                default: $this->defaultValue($field),
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
        );
    }

    private function phpType(Field $field): string
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

    private function isAutoIncrement(Field $field): bool
    {
        $default = $field->attribute('default');
        if ($default === null) {
            return false;
        }
        $val = $default->args[0] ?? null;
        return $val instanceof Invocation && $val->name === 'autoincrement';
    }

    private function defaultValue(Field $field): mixed
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
