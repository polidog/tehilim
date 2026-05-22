<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Migration\TableDef;
use RuntimeException;

abstract class AbstractPdoDriver implements Driver
{
    public function __construct(protected readonly PDO $pdoInstance)
    {
        $this->pdoInstance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdoInstance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdoInstance->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function pdo(): PDO
    {
        return $this->pdoInstance;
    }

    public function beginTransaction(?IsolationLevel $level = null): void
    {
        if ($level !== null) {
            throw new RuntimeException(
                sprintf('%s does not support isolation level overrides.', static::class),
            );
        }
        $this->pdoInstance->beginTransaction();
    }

    public function insertReturning(string $table, ?string $primaryKey, array $data, array $allColumns): array
    {
        $columns = array_keys($data);
        $quotedCols = array_map($this->quoteIdent(...), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdent($table),
            implode(', ', $quotedCols),
            implode(', ', $placeholders),
        );

        $stmt = $this->pdoInstance->prepare($sql);
        $stmt->execute(array_values($data));

        if ($primaryKey === null) {
            return $data;
        }

        $pkValue = $data[$primaryKey] ?? $this->pdoInstance->lastInsertId();
        if ($pkValue === false) {
            throw new RuntimeException("Cannot determine primary key after insert into {$table}");
        }

        return $this->fetchByPk($table, $primaryKey, $pkValue, $allColumns);
    }

    public function createTableSql(TableDef $def): string
    {
        $lines = [];
        foreach ($def->columns as $col) {
            $isInlinePrimary = $def->compositePrimaryKey === null
                && $def->primaryKey !== null
                && $def->primaryKey === $col->name
                && $this->primaryKeyInline();
            $lines[] = $this->columnSql($col, $isInlinePrimary);
        }

        if ($def->compositePrimaryKey !== null) {
            $cols = implode(', ', array_map($this->quoteIdent(...), $def->compositePrimaryKey));
            $lines[] = "PRIMARY KEY ({$cols})";
        } elseif ($def->primaryKey !== null && !$this->primaryKeyInline()) {
            $lines[] = 'PRIMARY KEY (' . $this->quoteIdent($def->primaryKey) . ')';
        }

        foreach ($def->uniqueColumns as $name) {
            if ($def->compositePrimaryKey === null && $name === $def->primaryKey) {
                continue;
            }
            $lines[] = 'UNIQUE (' . $this->quoteIdent($name) . ')';
        }

        foreach ($def->compositeUniqueGroups as $group) {
            $cols = implode(', ', array_map($this->quoteIdent(...), $group));
            $lines[] = "UNIQUE ({$cols})";
        }

        return sprintf(
            "CREATE TABLE %s (\n  %s\n)",
            $this->quoteIdent($def->name),
            implode(",\n  ", $lines),
        );
    }

    public function dropTableIfExistsSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdent($table);
    }

    public function createTableIfNotExistsSql(TableDef $def): string
    {
        $sql = $this->createTableSql($def);

        return preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql, 1) ?? $sql;
    }

    public function addColumnSql(string $table, ColumnDef $col): string
    {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s',
            $this->quoteIdent($table),
            $this->columnSql($col, false),
        );
    }

    public function dropColumnSql(string $table, string $col): string
    {
        return sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->quoteIdent($table),
            $this->quoteIdent($col),
        );
    }

    public function createUniqueIndexSql(string $table, array $columns, string $indexName): string
    {
        $cols = implode(', ', array_map($this->quoteIdent(...), $columns));

        return sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s)',
            $this->quoteIdent($indexName),
            $this->quoteIdent($table),
            $cols,
        );
    }

    public function dropIndexSql(string $indexName, string $table): string
    {
        return 'DROP INDEX ' . $this->quoteIdent($indexName);
    }

    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string
    {
        if ($columns === [] || $rowCount < 1) {
            throw new InvalidArgumentException('multiInsertSql: columns and rowCount must be non-empty');
        }
        $cols = implode(', ', array_map($this->quoteIdent(...), $columns));
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $values = implode(', ', array_fill(0, $rowCount, $tuple));

        return sprintf('INSERT INTO %s (%s) VALUES %s', $this->quoteIdent($table), $cols, $values);
    }

    public function cast(string $phpType, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($phpType) {
            'int', 'BigInt' => is_int($value) ? $value : (int) $value,
            'float' => (float) $value,
            'bool' => is_bool($value) ? $value : (bool) $value,
            'string' => (string) $value,
            'DateTime' => $value instanceof DateTimeInterface
                ? $value
                : new DateTimeImmutable((string) $value),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'bytes' => (string) $value,
            default => $value,
        };
    }

    public function bind(string $phpType, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($phpType) {
            'bool' => $value ? 1 : 0,
            'DateTime' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value,
            'json' => is_string($value) ? $value : json_encode($value),
            default => $value,
        };
    }

    public function jsonComparisonText(mixed $value): string
    {
        // PostgreSQL #>> and MySQL JSON_UNQUOTE both render JSON booleans as
        // the text 'true'/'false'. SqliteDriver overrides this.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Build a MySQL/SQLite-style JSON path string (`$."a"."b"`) from a key
     * list. Each segment is wrapped in double quotes so arbitrary keys stay
     * valid path syntax, with embedded `"`/`\` escaped.
     *
     * @param list<string> $path
     */
    protected function jsonPathDollar(array $path): string
    {
        $s = '$';
        foreach ($path as $seg) {
            $s .= '."' . str_replace(['\\', '"'], ['\\\\', '\"'], $seg) . '"';
        }

        return $s;
    }

    /**
     * Wrap an already-built path string as a single-quoted SQL string literal.
     */
    protected function quotePathLiteral(string $path): string
    {
        return "'" . str_replace("'", "''", $path) . "'";
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string,mixed>
     */
    protected function fetchByPk(string $table, string $pk, mixed $pkValue, array $columns): array
    {
        $cols = $columns === [] ? '*' : implode(', ', array_map($this->quoteIdent(...), $columns));
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $cols,
            $this->quoteIdent($table),
            $this->quoteIdent($pk),
        );
        $stmt = $this->pdoInstance->prepare($sql);
        $stmt->execute([$pkValue]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException("Inserted row not found in {$table} (pk={$pk})");
        }

        /** @var array<string,mixed> $row */
        return $row;
    }

    abstract protected function columnSql(ColumnDef $col, bool $isPrimary): string;

    protected function primaryKeyInline(): bool
    {
        return false;
    }
}
