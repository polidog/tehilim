<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use Polidog\Tehilim\Migration\ColumnDef;

final class MySqlDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function listTables(): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return array_map(strval(...), $rows);
    }

    public function dropIndexSql(string $indexName, string $table): string
    {
        return sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdent($indexName),
            $this->quoteIdent($table),
        );
    }

    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string
    {
        $sql = parent::multiInsertSql($table, $columns, $rowCount, $skipDuplicates);
        if ($skipDuplicates) {
            $sql = preg_replace('/^INSERT /', 'INSERT IGNORE ', $sql, 1) ?? $sql;
        }
        return $sql;
    }

    protected function columnSql(ColumnDef $col, bool $isPrimary): string
    {
        $parts = [$this->quoteIdent($col->name), $this->sqlType($col)];

        if (!$col->nullable) {
            $parts[] = 'NOT NULL';
        }
        if ($col->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }
        if ($col->default !== null && !$col->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->defaultLiteral($col);
        }

        return implode(' ', $parts);
    }

    private function sqlType(ColumnDef $col): string
    {
        return match ($col->phpType) {
            'int' => 'INT',
            'BigInt' => 'BIGINT',
            'float' => 'DOUBLE',
            'bool' => 'TINYINT(1)',
            'DateTime' => 'DATETIME',
            'json' => 'JSON',
            'bytes' => 'LONGBLOB',
            default => 'VARCHAR(255)',
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
