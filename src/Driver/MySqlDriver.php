<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Schema\IntrospectedColumn;
use Polidog\Tehilim\Schema\IntrospectedForeignKey;
use Polidog\Tehilim\Schema\IntrospectedTable;

final class MySqlDriver extends AbstractPdoDriver
{
    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function beginTransaction(?IsolationLevel $level = null): void
    {
        // MySQL: `SET TRANSACTION ISOLATION LEVEL ...` applies only to the
        // next transaction, so it must be issued immediately before BEGIN.
        if ($level !== null) {
            $this->pdoInstance->exec('SET TRANSACTION ISOLATION LEVEL ' . $level->value);
        }
        $this->pdoInstance->beginTransaction();
    }

    public function jsonExtractText(string $quotedColumn, array $path): string
    {
        return sprintf(
            'JSON_UNQUOTE(JSON_EXTRACT(%s, %s))',
            $quotedColumn,
            $this->quotePathLiteral($this->jsonPathDollar($path)),
        );
    }

    public function jsonContains(string $quotedColumn, array $path, mixed $value): array
    {
        $sql = sprintf(
            'JSON_CONTAINS(%s, ?, %s)',
            $quotedColumn,
            $this->quotePathLiteral($this->jsonPathDollar($path)),
        );

        return [$sql, json_encode($value)];
    }

    public function listTables(): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $rows);
    }

    public function introspectTable(string $table): IntrospectedTable
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA'
            . ' FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
            . ' ORDER BY ORDINAL_POSITION',
        );
        $stmt->execute([$table]);

        /** @var list<array{COLUMN_NAME:string,DATA_TYPE:string,COLUMN_TYPE:string,IS_NULLABLE:string,COLUMN_KEY:string,EXTRA:string}> $rows */
        $rows = $stmt->fetchAll();

        $pkCols = $this->primaryKeyColumns($table);
        $singlePk = count($pkCols) === 1 ? $pkCols[0] : null;

        [$uniqueSingle, $compositeUniques] = $this->uniqueGroups($table);

        $columns = [];
        foreach ($rows as $r) {
            $name = $r['COLUMN_NAME'];
            $isSinglePk = $name === $singlePk;
            $columns[] = new IntrospectedColumn(
                name: $name,
                tehilimType: $this->tehilimType($r['DATA_TYPE'], $r['COLUMN_TYPE']),
                nullable: strtoupper($r['IS_NULLABLE']) === 'YES',
                autoIncrement: $isSinglePk && str_contains(strtolower($r['EXTRA']), 'auto_increment'),
                primaryKey: $isSinglePk,
                unique: isset($uniqueSingle[$name]),
            );
        }

        return new IntrospectedTable(
            $table,
            $columns,
            count($pkCols) >= 2 ? $pkCols : null,
            $compositeUniques,
            $this->foreignKeys($table),
        );
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

    private function tehilimType(string $dataType, string $columnType): string
    {
        $t = strtolower($dataType);
        // tinyint(1) is MySQL's idiomatic boolean.
        if ($t === 'tinyint' && str_contains(strtolower($columnType), '(1)')) {
            return 'Boolean';
        }

        return match ($t) {
            'bigint' => 'BigInt',
            'int', 'integer', 'smallint', 'mediumint', 'tinyint' => 'Int',
            'double', 'float', 'real' => 'Float',
            'decimal', 'numeric' => 'Decimal',
            'datetime', 'timestamp' => 'DateTime',
            'json' => 'Json',
            'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary' => 'Bytes',
            default => 'String',
        };
    }

    /**
     * Single-column foreign keys (composite FKs are skipped).
     *
     * @return list<IntrospectedForeignKey>
     */
    private function foreignKeys(string $table): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT constraint_name, column_name, referenced_table_name, referenced_column_name'
            . ' FROM information_schema.key_column_usage'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
            . ' AND referenced_table_name IS NOT NULL'
            . ' ORDER BY constraint_name, ordinal_position',
        );
        $stmt->execute([$table]);

        /** @var list<array{constraint_name:string,column_name:string,referenced_table_name:string,referenced_column_name:string}> $rows */
        $rows = $stmt->fetchAll();

        $countByName = [];
        foreach ($rows as $r) {
            $countByName[$r['constraint_name']] = ($countByName[$r['constraint_name']] ?? 0) + 1;
        }

        $fks = [];
        foreach ($rows as $r) {
            if (($countByName[$r['constraint_name']] ?? 0) !== 1) {
                continue; // composite FK
            }
            $fks[] = new IntrospectedForeignKey(
                $r['column_name'],
                $r['referenced_table_name'],
                $r['referenced_column_name'],
            );
        }

        return $fks;
    }

    /** @return list<string> primary key columns in order */
    private function primaryKeyColumns(string $table): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT COLUMN_NAME FROM information_schema.key_column_usage'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
            . " AND constraint_name = 'PRIMARY' ORDER BY ORDINAL_POSITION",
        );
        $stmt->execute([$table]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array{0:array<string,true>,1:list<list<string>>} single uniques keyed by column, plus composite groups
     */
    private function uniqueGroups(string $table): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
            . " AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'"
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        );
        $stmt->execute([$table]);

        /** @var list<array{INDEX_NAME:string,COLUMN_NAME:string}> $rows */
        $rows = $stmt->fetchAll();

        $byIndex = [];
        foreach ($rows as $r) {
            $byIndex[$r['INDEX_NAME']][] = $r['COLUMN_NAME'];
        }

        $single = [];
        $composite = [];
        foreach ($byIndex as $cols) {
            if (count($cols) === 1) {
                $single[$cols[0]] = true;
            } else {
                $composite[] = $cols;
            }
        }

        return [$single, $composite];
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
