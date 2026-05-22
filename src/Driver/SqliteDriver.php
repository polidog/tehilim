<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Schema\IntrospectedColumn;
use Polidog\Tehilim\Schema\IntrospectedForeignKey;
use Polidog\Tehilim\Schema\IntrospectedTable;
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
        // json_extract returns typed scalars (ints stay ints, JSON booleans
        // come back as 0/1). CAST AS TEXT pins the result to text so numeric
        // and string `equals` compare the same way they do on PG/MySQL.
        return sprintf(
            'CAST(json_extract(%s, %s) AS TEXT)',
            $quotedColumn,
            $this->quotePathLiteral($this->jsonPathDollar($path)),
        );
    }

    public function jsonComparisonText(mixed $value): string
    {
        // SQLite has no native boolean: JSON true/false extract (and CAST) to
        // '1'/'0', so a boolean candidate must compare against those.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
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

    public function introspectTable(string $table): IntrospectedTable
    {
        $info = $this->pdoInstance->prepare(
            'SELECT name, type, "notnull", pk FROM pragma_table_info(?)',
        );
        $info->execute([$table]);

        /** @var list<array{name:string,type:string,notnull:int|string,pk:int|string}> $rows */
        $rows = $info->fetchAll();

        $uniqueSingle = [];
        $compositeUniques = [];
        foreach ($this->uniqueIndexGroups($table) as $cols) {
            if (count($cols) === 1) {
                $uniqueSingle[$cols[0]] = true;
            } else {
                $compositeUniques[] = $cols;
            }
        }

        // Resolve the primary key columns first (ordered by their pk position),
        // so we know whether it's single or composite before building columns.
        $pkOrder = [];
        foreach ($rows as $r) {
            if ((int) $r['pk'] > 0) {
                $pkOrder[(int) $r['pk']] = $r['name'];
            }
        }
        ksort($pkOrder);
        $pkCols = array_values($pkOrder);
        $singlePk = count($pkCols) === 1 ? $pkCols[0] : null;

        $columns = [];
        foreach ($rows as $r) {
            $isSinglePk = $r['name'] === $singlePk;
            $type = $this->tehilimType($r['type']);
            $columns[] = new IntrospectedColumn(
                name: $r['name'],
                tehilimType: $type,
                nullable: (int) $r['notnull'] === 0 && (int) $r['pk'] === 0,
                // A single-column INTEGER PK is a rowid alias in SQLite: it
                // auto-generates on NULL/omitted insert, with or without the
                // AUTOINCREMENT keyword. Treat it as @default(autoincrement()).
                autoIncrement: $isSinglePk && $type === 'Int',
                primaryKey: $isSinglePk,
                unique: isset($uniqueSingle[$r['name']]),
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

    /**
     * Map a SQLite declared type back to a schema type. SQLite's type affinity
     * is lossy: it only distinguishes INTEGER/REAL/TEXT/BLOB, so DateTime, Json,
     * Boolean and BigInt all collapse into Int/String here.
     */
    private function tehilimType(string $declared): string
    {
        $t = strtoupper($declared);

        return match (true) {
            str_contains($t, 'INT') => 'Int',
            str_contains($t, 'REAL'), str_contains($t, 'FLOA'), str_contains($t, 'DOUB') => 'Float',
            str_contains($t, 'BLOB') => 'Bytes',
            default => 'String',
        };
    }

    /**
     * Single-column foreign keys. Composite FKs (multiple rows sharing an `id`)
     * are skipped — relations are single-column in tehilim.
     *
     * @return list<IntrospectedForeignKey>
     */
    private function foreignKeys(string $table): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT id, "table", "from", "to" FROM pragma_foreign_key_list(?)',
        );
        $stmt->execute([$table]);

        /** @var list<array{id:int|string,table:string,from:string,to:string}> $rows */
        $rows = $stmt->fetchAll();

        $countById = [];
        foreach ($rows as $r) {
            $countById[$r['id']] = ($countById[$r['id']] ?? 0) + 1;
        }

        $fks = [];
        foreach ($rows as $r) {
            if (($countById[$r['id']] ?? 0) !== 1) {
                continue; // composite FK
            }
            $fks[] = new IntrospectedForeignKey($r['from'], $r['table'], $r['to']);
        }

        return $fks;
    }

    /**
     * Unique column groups (excluding the PK index) as lists of column names.
     *
     * @return list<list<string>>
     */
    private function uniqueIndexGroups(string $table): array
    {
        $list = $this->pdoInstance->prepare(
            'SELECT name, "unique", origin FROM pragma_index_list(?)',
        );
        $list->execute([$table]);

        /** @var list<array{name:string,unique:int|string,origin:string}> $indexes */
        $indexes = $list->fetchAll();

        $groups = [];
        foreach ($indexes as $idx) {
            if ((int) $idx['unique'] !== 1 || $idx['origin'] === 'pk') {
                continue;
            }
            $cols = $this->pdoInstance->prepare('SELECT name FROM pragma_index_info(?)');
            $cols->execute([$idx['name']]);

            /** @var list<string> $names */
            $names = array_map(strval(...), $cols->fetchAll(PDO::FETCH_COLUMN));
            if ($names !== []) {
                $groups[] = $names;
            }
        }

        return $groups;
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
