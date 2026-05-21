<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Migration\TableDef;

interface Driver
{
    public function pdo(): PDO;

    /**
     * Begin a top-level transaction, optionally setting its isolation level.
     *
     * Implementations must emit the driver-appropriate `SET TRANSACTION` /
     * `BEGIN ... ISOLATION LEVEL` sequencing when $level is non-null. Drivers
     * that cannot emit a corresponding statement at all (e.g. SQLite for
     * anything other than SERIALIZABLE) must throw rather than fall back to
     * a different level on the client side.
     *
     * Note: this contract is about what the driver sends — it does not
     * promise the server will honor every level. PostgreSQL, for instance,
     * accepts `READ UNCOMMITTED` and treats it as `READ COMMITTED` per the
     * SQL standard; that is server-side behavior, not a driver downgrade.
     *
     * If begin succeeds it must leave the connection inside a clean
     * transaction; if it throws, the connection must be left transaction-free
     * (drivers are responsible for rolling back any half-opened transaction
     * before propagating).
     */
    public function beginTransaction(?IsolationLevel $level = null): void;

    public function quoteIdent(string $name): string;

    /**
     * Insert a row and return the row including any DB-generated values.
     *
     * @param array<string,mixed> $data       column => value
     * @param list<string>        $allColumns full column list to select back
     *
     * @return array<string,mixed>
     */
    public function insertReturning(string $table, ?string $primaryKey, array $data, array $allColumns): array;

    public function createTableSql(TableDef $def): string;

    public function createTableIfNotExistsSql(TableDef $def): string;

    public function dropTableIfExistsSql(string $table): string;

    /**
     * List user tables currently present in the database (excluding system
     * tables). Used by destructive `push` to clean up between schemas.
     *
     * @return list<string>
     */
    public function listTables(): array;

    public function addColumnSql(string $table, ColumnDef $col): string;

    public function dropColumnSql(string $table, string $col): string;

    /**
     * @param list<string> $columns
     */
    public function createUniqueIndexSql(string $table, array $columns, string $indexName): string;

    /**
     * Returns INSERT ... VALUES (?, ?), (?, ?), ... statement for $rowCount
     * rows of $columns columns, optionally skipping on PK/unique conflicts.
     *
     * @param list<string> $columns
     */
    public function multiInsertSql(string $table, array $columns, int $rowCount, bool $skipDuplicates): string;

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
