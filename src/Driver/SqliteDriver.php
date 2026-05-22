<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use RuntimeException;

final class SqliteDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function beginTransaction(?IsolationLevel $level = null): void
    {
        // SQLite serializes write transactions and offers SERIALIZABLE-grade
        // isolation only. Accept SERIALIZABLE as a no-op, reject anything
        // else so callers don't get a silently weaker guarantee than they
        // asked for.
        if ($level !== null && $level !== IsolationLevel::Serializable) {
            throw new RuntimeException(
                'SQLite only supports IsolationLevel::Serializable.',
            );
        }
        $this->pdoInstance->beginTransaction();
    }

    public function jsonExtractText(string $quotedColumn, array $path): string
    {
        return sprintf(
            'json_extract(%s, %s)',
            $quotedColumn,
            $this->quotePathLiteral($this->jsonPathDollar($path)),
        );
    }

    public function jsonContains(string $quotedColumn, array $path, mixed $value): array
    {
        $sql = sprintf(
            'EXISTS (SELECT 1 FROM json_each(%s, %s) WHERE value = ?)',
            $quotedColumn,
            $this->quotePathLiteral($this->jsonPathDollar($path)),
        );

        return [$sql, $value];
    }

    public function listTables(): array
    {
        $stmt = $this->pdoInstance->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $rows);
    }

    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string
    {
        $sql = parent::multiInsertSql($table, $columns, $rowCount, $skipDuplicates);
        if ($skipDuplicates) {
            $sql = preg_replace('/^INSERT /', 'INSERT OR IGNORE ', $sql, 1) ?? $sql;
        }

        return $sql;
    }

    protected function primaryKeyInline(): bool
    {
        return true;
    }

    protected function columnSql(ColumnDef $col, bool $isPrimary): string
    {
        $type = $this->sqlType($col);
        $parts = [$this->quoteIdent($col->name), $type];

        if ($isPrimary) {
            $parts[] = 'PRIMARY KEY';
            if ($col->autoIncrement) {
                $parts[] = 'AUTOINCREMENT';
            }
        }
        if (!$col->nullable && !$isPrimary) {
            $parts[] = 'NOT NULL';
        }
        if ($col->default !== null && !$col->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->defaultLiteral($col);
        }

        return implode(' ', $parts);
    }

    private function sqlType(ColumnDef $col): string
    {
        return match ($col->phpType) {
            'int', 'BigInt' => 'INTEGER',
            'float' => 'REAL',
            'bool' => 'INTEGER',
            'DateTime' => 'TEXT',
            'json' => 'TEXT',
            'bytes' => 'BLOB',
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
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return "'" . str_replace("'", "''", (string) $v) . "'";
    }
}
