<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use Polidog\Tehilim\Migration\ColumnDef;

final class SqliteDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    protected function primaryKeyInline(): bool
    {
        return true;
    }

    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string
    {
        $sql = parent::multiInsertSql($table, $columns, $rowCount, $skipDuplicates);
        if ($skipDuplicates) {
            $sql = preg_replace('/^INSERT /', 'INSERT OR IGNORE ', $sql, 1) ?? $sql;
        }
        return $sql;
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
