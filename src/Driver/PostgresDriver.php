<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use RuntimeException;
use Throwable;

final class PostgresDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function beginTransaction(?IsolationLevel $level = null): void
    {
        // PostgreSQL: SET TRANSACTION must run inside the transaction, before
        // any other statement runs against it. If SET fails the transaction is
        // already open, so we must roll back before propagating — otherwise a
        // persistent connection would leak a dangling transaction.
        $this->pdoInstance->beginTransaction();
        if ($level === null) {
            return;
        }

        try {
            $this->pdoInstance->exec('SET TRANSACTION ISOLATION LEVEL ' . $level->value);
        } catch (Throwable $e) {
            try {
                $this->pdoInstance->rollBack();
            } catch (Throwable) {
                // best-effort; surface the original SET TRANSACTION failure
            }

            throw $e;
        }
    }

    public function jsonExtractText(string $quotedColumn, array $path): string
    {
        // #>> returns the element at the path as text.
        return sprintf(
            '(%s #>> %s)',
            $quotedColumn,
            $this->quotePathLiteral($this->pgPathArray($path)),
        );
    }

    public function jsonContains(string $quotedColumn, array $path, mixed $value): array
    {
        // #> yields the element as jsonb; @> tests containment of the candidate.
        $sql = sprintf(
            '(%s #> %s)::jsonb @> ?::jsonb',
            $quotedColumn,
            $this->quotePathLiteral($this->pgPathArray($path)),
        );

        return [$sql, json_encode($value)];
    }

    public function listTables(): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT tablename FROM pg_tables WHERE schemaname = ANY (current_schemas(false))'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $rows);
    }

    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string
    {
        $sql = parent::multiInsertSql($table, $columns, $rowCount, $skipDuplicates);
        if ($skipDuplicates) {
            $sql .= ' ON CONFLICT DO NOTHING';
        }

        return $sql;
    }

    public function insertReturning(string $table, ?string $primaryKey, array $data, array $allColumns): array
    {
        $columns = array_keys($data);
        $quotedCols = array_map($this->quoteIdent(...), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $returningCols = $allColumns === []
            ? '*'
            : implode(', ', array_map($this->quoteIdent(...), $allColumns));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            $this->quoteIdent($table),
            implode(', ', $quotedCols),
            implode(', ', $placeholders),
            $returningCols,
        );

        $stmt = $this->pdoInstance->prepare($sql);
        $stmt->execute(array_values($data));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException("INSERT ... RETURNING produced no row for {$table}");
        }

        /** @var array<string,mixed> $row */
        return $row;
    }

    protected function columnSql(ColumnDef $col, bool $isPrimary): string
    {
        $parts = [$this->quoteIdent($col->name), $this->sqlType($col)];

        if (!$col->nullable) {
            $parts[] = 'NOT NULL';
        }
        if ($col->default !== null && !$col->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->defaultLiteral($col);
        }

        return implode(' ', $parts);
    }

    /**
     * Build a PostgreSQL text[] path literal (`{"a","b"}`) for the `#>` / `#>>`
     * operators. Each segment is double-quoted with `"`/`\` escaped.
     *
     * @param list<string> $path
     */
    private function pgPathArray(array $path): string
    {
        $segs = array_map(
            static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $s) . '"',
            $path,
        );

        return '{' . implode(',', $segs) . '}';
    }

    private function sqlType(ColumnDef $col): string
    {
        if ($col->autoIncrement && $col->phpType === 'int') {
            return 'SERIAL';
        }
        if ($col->autoIncrement && $col->phpType === 'BigInt') {
            return 'BIGSERIAL';
        }

        return match ($col->phpType) {
            'int' => 'INTEGER',
            'BigInt' => 'BIGINT',
            'float' => 'DOUBLE PRECISION',
            'bool' => 'BOOLEAN',
            'DateTime' => 'TIMESTAMP',
            'json' => 'JSONB',
            'bytes' => 'BYTEA',
            default => 'TEXT',
        };
    }

    private function defaultLiteral(ColumnDef $col): string
    {
        $v = $col->default;
        if ($v === 'now()' || $v === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return "'" . str_replace("'", "''", (string) $v) . "'";
    }
}
