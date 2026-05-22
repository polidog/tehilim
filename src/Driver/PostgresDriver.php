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

    public function introspectTable(string $table): IntrospectedTable
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT column_name, data_type, is_nullable, is_identity, column_default'
            . ' FROM information_schema.columns'
            . ' WHERE table_schema = ANY (current_schemas(false)) AND table_name = ?'
            . ' ORDER BY ordinal_position',
        );
        $stmt->execute([$table]);

        /** @var list<array{column_name:string,data_type:string,is_nullable:string,is_identity:string,column_default:?string}> $rows */
        $rows = $stmt->fetchAll();

        $pkCols = $this->constraintColumns($table, 'PRIMARY KEY');
        $singlePk = count($pkCols) === 1 ? $pkCols[0] : null;

        [$uniqueSingle, $compositeUniques] = $this->uniqueGroups($table);

        $columns = [];
        foreach ($rows as $r) {
            $name = $r['column_name'];
            $isSinglePk = $name === $singlePk;
            $default = $r['column_default'];
            $isSerial = is_string($default) && str_starts_with($default, 'nextval(');
            $isIdentity = strtoupper($r['is_identity']) === 'YES';
            $columns[] = new IntrospectedColumn(
                name: $name,
                tehilimType: $this->tehilimType($r['data_type']),
                nullable: strtoupper($r['is_nullable']) === 'YES',
                autoIncrement: $isSinglePk && ($isSerial || $isIdentity),
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

    private function tehilimType(string $dataType): string
    {
        return match (strtolower($dataType)) {
            'integer', 'int', 'int4', 'smallint' => 'Int',
            'bigint', 'int8' => 'BigInt',
            'double precision', 'real', 'float8', 'float4' => 'Float',
            'numeric', 'decimal' => 'Decimal',
            'boolean', 'bool' => 'Boolean',
            'timestamp without time zone', 'timestamp with time zone', 'timestamp', 'date' => 'DateTime',
            'jsonb', 'json' => 'Json',
            'bytea' => 'Bytes',
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
            'SELECT tc.constraint_name, kcu.column_name,'
            . ' ccu.table_name AS referenced_table, ccu.column_name AS referenced_column'
            . ' FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.key_column_usage kcu'
            . ' ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema'
            . ' JOIN information_schema.constraint_column_usage ccu'
            . ' ON tc.constraint_name = ccu.constraint_name AND tc.table_schema = ccu.table_schema'
            . ' WHERE tc.table_schema = ANY (current_schemas(false))'
            . " AND tc.table_name = ? AND tc.constraint_type = 'FOREIGN KEY'"
            . ' ORDER BY tc.constraint_name, kcu.ordinal_position',
        );
        $stmt->execute([$table]);

        /** @var list<array{constraint_name:string,column_name:string,referenced_table:string,referenced_column:string}> $rows */
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
                $r['referenced_table'],
                $r['referenced_column'],
            );
        }

        return $fks;
    }

    /**
     * @return list<string> columns of the table's constraint of the given type, in order
     */
    private function constraintColumns(string $table, string $constraintType): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT kcu.column_name'
            . ' FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.key_column_usage kcu'
            . ' ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema'
            . ' WHERE tc.table_schema = ANY (current_schemas(false))'
            . ' AND tc.table_name = ? AND tc.constraint_type = ?'
            . ' ORDER BY kcu.ordinal_position',
        );
        $stmt->execute([$table, $constraintType]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array{0:array<string,true>,1:list<list<string>>} single uniques keyed by column, plus composite groups
     */
    private function uniqueGroups(string $table): array
    {
        $stmt = $this->pdoInstance->prepare(
            'SELECT tc.constraint_name, kcu.column_name'
            . ' FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.key_column_usage kcu'
            . ' ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema'
            . ' WHERE tc.table_schema = ANY (current_schemas(false))'
            . " AND tc.table_name = ? AND tc.constraint_type = 'UNIQUE'"
            . ' ORDER BY tc.constraint_name, kcu.ordinal_position',
        );
        $stmt->execute([$table]);

        /** @var list<array{constraint_name:string,column_name:string}> $rows */
        $rows = $stmt->fetchAll();

        $byConstraint = [];
        foreach ($rows as $r) {
            $byConstraint[$r['constraint_name']][] = $r['column_name'];
        }

        $single = [];
        $composite = [];
        foreach ($byConstraint as $cols) {
            if (count($cols) === 1) {
                $single[$cols[0]] = true;
            } else {
                $composite[] = $cols;
            }
        }

        return [$single, $composite];
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
