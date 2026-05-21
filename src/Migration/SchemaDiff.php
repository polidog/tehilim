<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * Produces a list of DDL statements turning $from into $to. Only the additive
 * / drop subset is handled in v1; column type or default changes emit an
 * SQL comment so the user can decide what to do manually.
 */
final class SchemaDiff
{
    /**
     * @return list<string> SQL statements (driver-specific).
     */
    public function diff(Schema $from, Schema $to, Driver $driver): array
    {
        $oldTables = $this->byName(TableBuilder::fromSchema($from));
        $newTables = $this->byName(TableBuilder::fromSchema($to));

        $out = [];

        foreach (array_diff(array_keys($oldTables), array_keys($newTables)) as $dropped) {
            $out[] = $driver->dropTableIfExistsSql($dropped) . ';';
        }

        foreach ($newTables as $name => $table) {
            if (!isset($oldTables[$name])) {
                $out[] = $driver->createTableSql($table) . ';';
                continue;
            }
            $out = array_merge($out, $this->diffTable($oldTables[$name], $table, $driver));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function diffTable(TableDef $old, TableDef $new, Driver $driver): array
    {
        $out = [];

        $oldCols = $this->indexColumns($old);
        $newCols = $this->indexColumns($new);

        foreach ($newCols as $name => $col) {
            if (!isset($oldCols[$name])) {
                $out[] = $driver->addColumnSql($new->name, $col) . ';';
                continue;
            }
            $changes = $this->columnDifferences($oldCols[$name], $col);
            if ($changes !== []) {
                $out[] = sprintf(
                    "-- MANUAL: column %s.%s changed (%s) — Tehilim v1 does not auto-alter; please edit this migration.",
                    $new->name,
                    $name,
                    implode(', ', $changes),
                );
            }
        }

        foreach ($oldCols as $name => $_col) {
            if (!isset($newCols[$name])) {
                $out[] = $driver->dropColumnSql($new->name, $name) . ';';
            }
        }

        $oldUnique = array_diff($old->uniqueColumns, [$old->primaryKey]);
        $newUnique = array_diff($new->uniqueColumns, [$new->primaryKey]);

        foreach (array_diff($newUnique, $oldUnique) as $col) {
            $idx = $this->indexName($new->name, [$col]);
            $out[] = $driver->createUniqueIndexSql($new->name, [$col], $idx) . ';';
        }
        foreach (array_diff($oldUnique, $newUnique) as $col) {
            $idx = $this->indexName($new->name, [$col]);
            $out[] = $driver->dropIndexSql($idx, $new->name) . ';';
        }

        $oldComposite = array_map(fn (array $g): string => implode(',', $g), $old->compositeUniqueGroups);
        $newComposite = array_map(fn (array $g): string => implode(',', $g), $new->compositeUniqueGroups);

        foreach ($new->compositeUniqueGroups as $i => $group) {
            if (in_array($newComposite[$i], $oldComposite, true)) {
                continue;
            }
            $idx = $this->indexName($new->name, $group);
            $out[] = $driver->createUniqueIndexSql($new->name, $group, $idx) . ';';
        }
        foreach ($old->compositeUniqueGroups as $i => $group) {
            if (in_array($oldComposite[$i], $newComposite, true)) {
                continue;
            }
            $idx = $this->indexName($new->name, $group);
            $out[] = $driver->dropIndexSql($idx, $new->name) . ';';
        }

        if ($old->pkColumns() !== $new->pkColumns()) {
            $out[] = sprintf(
                "-- MANUAL: primary key on %s changed (%s -> %s); Tehilim v1 does not auto-alter PKs.",
                $new->name,
                implode(',', $old->pkColumns()) ?: '<none>',
                implode(',', $new->pkColumns()) ?: '<none>',
            );
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function columnDifferences(ColumnDef $a, ColumnDef $b): array
    {
        $diff = [];
        if ($a->phpType !== $b->phpType) {
            $diff[] = "type {$a->phpType} -> {$b->phpType}";
        }
        if ($a->nullable !== $b->nullable) {
            $diff[] = $b->nullable ? 'now nullable' : 'now NOT NULL';
        }
        if ($a->default !== $b->default) {
            $diff[] = 'default changed';
        }
        if ($a->autoIncrement !== $b->autoIncrement) {
            $diff[] = 'autoIncrement changed';
        }
        return $diff;
    }

    /**
     * @param list<TableDef> $tables
     * @return array<string,TableDef>
     */
    private function byName(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t->name] = $t;
        }
        return $out;
    }

    /**
     * @return array<string,ColumnDef>
     */
    private function indexColumns(TableDef $table): array
    {
        $out = [];
        foreach ($table->columns as $col) {
            $out[$col->name] = $col;
        }
        return $out;
    }

    /** @param list<string> $columns */
    private function indexName(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_key';
    }
}
