<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Driver;

use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\MySqlDriver;
use Polidog\Tehilim\Driver\PostgresDriver;
use Polidog\Tehilim\Driver\SqliteDriver;

/**
 * Verifies the dialect-specific JSON SQL each driver emits. PostgreSQL/MySQL
 * can't run in CI, so we drive them with an in-memory SQLite PDO (the JSON
 * builders are pure string functions that never touch the connection).
 */
final class JsonSqlTest extends TestCase
{
    public function testSqlite(): void
    {
        $d = new SqliteDriver($this->pdo());

        self::assertSame(
            'CAST(json_extract("profile", \'$."address"."city"\') AS TEXT)',
            $d->jsonExtractText('"profile"', ['address', 'city']),
        );

        [$sql, $bind] = $d->jsonContains('"tags"', ['tags'], 'php');
        self::assertSame('EXISTS (SELECT 1 FROM json_each("tags", \'$."tags"\') WHERE value = ?)', $sql);
        self::assertSame('php', $bind, 'SQLite binds the raw scalar');

        // SQLite renders JSON booleans as 1/0, not true/false.
        self::assertSame('1', $d->jsonComparisonText(true));
        self::assertSame('0', $d->jsonComparisonText(false));
        self::assertSame('42', $d->jsonComparisonText(42));
    }

    public function testMysql(): void
    {
        $d = new MySqlDriver($this->pdo());

        self::assertSame(
            'JSON_UNQUOTE(JSON_EXTRACT(`profile`, \'$."address"."city"\'))',
            $d->jsonExtractText('`profile`', ['address', 'city']),
        );

        [$sql, $bind] = $d->jsonContains('`tags`', ['tags'], 'php');
        self::assertSame('JSON_CONTAINS(`tags`, ?, \'$."tags"\')', $sql);
        self::assertSame('"php"', $bind, 'MySQL binds a JSON-encoded candidate');

        self::assertSame('true', $d->jsonComparisonText(true), 'MySQL JSON_UNQUOTE yields true/false');
    }

    public function testPostgres(): void
    {
        $d = new PostgresDriver($this->pdo());

        self::assertSame(
            '("profile" #>> \'{"address","city"}\')',
            $d->jsonExtractText('"profile"', ['address', 'city']),
        );

        // Scalar candidate is correct: PG treats an array as containing a
        // primitive, so `'["php"]'::jsonb @> '"php"'::jsonb` is true.
        [$sql, $bind] = $d->jsonContains('"tags"', ['tags'], 'php');
        self::assertSame('("tags" #> \'{"tags"}\')::jsonb @> ?::jsonb', $sql);
        self::assertSame('"php"', $bind, 'PostgreSQL binds a JSON-encoded candidate');

        self::assertSame('true', $d->jsonComparisonText(true), 'PostgreSQL #>> yields true/false');
    }

    public function testPathSegmentsAreEscaped(): void
    {
        $sqlite = new SqliteDriver($this->pdo());
        // A key containing a double quote (path syntax) and a single quote
        // (SQL string literal) must both be neutralized.
        self::assertSame(
            'CAST(json_extract("c", \'$."a\"b"."x\'\'y"\') AS TEXT)',
            $sqlite->jsonExtractText('"c"', ['a"b', "x'y"]),
        );

        $pg = new PostgresDriver($this->pdo());
        self::assertSame(
            '("c" #>> \'{"a\"b"}\')',
            $pg->jsonExtractText('"c"', ['a"b']),
        );
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}
