<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Migration\TableDef;

interface Driver
{
    public function pdo(): PDO;

    public function quoteIdent(string $name): string;

    /**
     * Insert a row and return the row including any DB-generated values.
     *
     * @param array<string,mixed> $data       column => value
     * @param list<string>        $allColumns full column list to select back
     * @return array<string,mixed>
     */
    public function insertReturning(string $table, ?string $primaryKey, array $data, array $allColumns): array;

    public function createTableSql(TableDef $def): string;

    public function createTableIfNotExistsSql(TableDef $def): string;

    public function dropTableIfExistsSql(string $table): string;

    public function addColumnSql(string $table, ColumnDef $col): string;

    public function dropColumnSql(string $table, string $col): string;

    /**
     * @param list<string> $columns
     */
    public function createUniqueIndexSql(string $table, array $columns, string $indexName): string;

    public function dropIndexSql(string $indexName, string $table): string;

    /**
     * Convert a value coming back from PDO into the schema-declared PHP type.
     */
    public function cast(string $phpType, mixed $value): mixed;

    /**
     * Convert a PHP value into a bind-ready value for the given column type.
     */
    public function bind(string $phpType, mixed $value): mixed;
}
