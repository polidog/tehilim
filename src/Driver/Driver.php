<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;
use Polidog\Tehilim\Client\IsolationLevel;
use Polidog\Tehilim\Migration\ColumnDef;
use Polidog\Tehilim\Migration\TableDef;
use Polidog\Tehilim\Schema\IntrospectedTable;

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
     * Whether DDL statements participate in transactions. False for MySQL,
     * where each DDL statement triggers an implicit commit, so wrapping a
     * migration in a transaction would give a false sense of atomicity. True
     * for SQLite and PostgreSQL, which support transactional DDL.
     */
    public function supportsTransactionalDdl(): bool;

    /**
     * The ` ESCAPE '...'` suffix appended to LIKE clauses so the backslash the
     * query compiler uses to escape `%`/`_`/`\` in user patterns is honored.
     * Empty where the dialect's default LIKE escape is already a backslash
     * (MySQL, PostgreSQL); SQLite has no default and must declare one.
     */
    public function likeEscapeClause(): string;

    /**
     * SQL expression that extracts the value at $path from a JSON column as
     * text, for use in comparisons (`= ?`, `LIKE ?`, etc).
     *
     * $quotedColumn must already be quoted via {@see quoteIdent()}. $path keys
     * are embedded directly into the SQL (a JSON path cannot be bound as a
     * parameter), so implementations must escape each segment safely.
     *
     * @param list<string> $path
     */
    public function jsonExtractText(string $quotedColumn, array $path): string;

    /**
     * Predicate testing whether the JSON array at $path contains $value.
     * Returns a `[sql, boundValue]` pair: the SQL carries exactly one `?`
     * placeholder and the caller binds boundValue for it. The bound value is
     * driver-shaped (JSON-encoded for PostgreSQL/MySQL, raw scalar for SQLite).
     *
     * @param list<string> $path
     *
     * @return array{0:string,1:mixed}
     */
    public function jsonContains(string $quotedColumn, array $path, mixed $value): array;

    /**
     * Normalize a scalar so it compares correctly against the text produced by
     * {@see jsonExtractText()}. Mainly handles booleans, whose textual form
     * differs by dialect (PostgreSQL/MySQL yield `true`/`false`, SQLite `1`/`0`).
     */
    public function jsonComparisonText(mixed $value): string;

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

    /**
     * Read a live table's structure back into a dialect-neutral
     * {@see IntrospectedTable} for `db pull`. Column types are mapped to
     * schema-level type names; PK / unique (single + composite) and
     * auto-increment are detected. Relations are not inferred here.
     */
    public function introspectTable(string $table): IntrospectedTable;

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
