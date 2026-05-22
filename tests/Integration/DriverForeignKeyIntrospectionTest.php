<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Driver\MySqlDriver;
use Polidog\Tehilim\Driver\PostgresDriver;

/**
 * Opt-in checks for MySQL / PostgreSQL FK introspection, which CI can't run.
 * Set MYSQL_DSN / POSTGRES_DSN (against a throwaway database) to exercise them:
 *
 *   MYSQL_DSN='mysql:host=127.0.0.1;dbname=test;user=root' vendor/bin/phpunit
 *   POSTGRES_DSN='pgsql:host=127.0.0.1;dbname=test;user=postgres' vendor/bin/phpunit
 *
 * Each test drops and recreates its tables, then asserts the FK is read back.
 */
final class DriverForeignKeyIntrospectionTest extends TestCase
{
    public function testMysqlForeignKeyIntrospection(): void
    {
        $dsn = getenv('MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('set MYSQL_DSN to run MySQL FK introspection');
        }

        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec($pdo, [
            'DROP TABLE IF EXISTS `fk_post`',
            'DROP TABLE IF EXISTS `fk_user`',
            'CREATE TABLE `fk_user` (`id` INT PRIMARY KEY AUTO_INCREMENT)',
            'CREATE TABLE `fk_post` (`id` INT PRIMARY KEY AUTO_INCREMENT, `authorId` INT NOT NULL, FOREIGN KEY (`authorId`) REFERENCES `fk_user`(`id`))',
        ]);

        try {
            $this->assertSingleFk(new MySqlDriver($pdo), 'fk_post', 'authorId', 'fk_user', 'id');
        } finally {
            $this->exec($pdo, ['DROP TABLE IF EXISTS `fk_post`', 'DROP TABLE IF EXISTS `fk_user`']);
        }
    }

    public function testPostgresForeignKeyIntrospection(): void
    {
        $dsn = getenv('POSTGRES_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('set POSTGRES_DSN to run PostgreSQL FK introspection');
        }

        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec($pdo, [
            'DROP TABLE IF EXISTS "fk_post"',
            'DROP TABLE IF EXISTS "fk_user"',
            'CREATE TABLE "fk_user" ("id" SERIAL PRIMARY KEY)',
            'CREATE TABLE "fk_post" ("id" SERIAL PRIMARY KEY, "authorId" INTEGER NOT NULL REFERENCES "fk_user"("id"))',
        ]);

        try {
            $this->assertSingleFk(new PostgresDriver($pdo), 'fk_post', 'authorId', 'fk_user', 'id');
        } finally {
            $this->exec($pdo, ['DROP TABLE IF EXISTS "fk_post"', 'DROP TABLE IF EXISTS "fk_user"']);
        }
    }

    private function assertSingleFk(Driver $driver, string $table, string $column, string $refTable, string $refColumn): void
    {
        $fks = $driver->introspectTable($table)->foreignKeys;
        self::assertCount(1, $fks);
        self::assertSame($column, $fks[0]->column);
        self::assertSame($refTable, $fks[0]->referencedTable);
        self::assertSame($refColumn, $fks[0]->referencedColumn);
    }

    /**
     * @param list<string> $statements
     */
    private function exec(PDO $pdo, array $statements): void
    {
        foreach ($statements as $sql) {
            $pdo->prepare($sql)->execute();
        }
    }
}
