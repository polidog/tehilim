<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Migration\ColumnDef;
use RuntimeException;

final class PostgresDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
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
