<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Migration\MigrationStore;
use Polidog\Tehilim\Migration\Migrator;
use Polidog\Tehilim\Migration\SchemaDiff;
use Polidog\Tehilim\Schema\Ast\Schema as SchemaAst;
use Polidog\Tehilim\Schema\Parser;

final class MigrationsTest extends TestCase
{
    private string $workDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-mig-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
        $this->dbPath = $this->workDir . '/dev.sqlite';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testDevCreatesAndAppliesIncrementalMigrations(): void
    {
        $schemaPath = $this->workDir . '/schema.tehilim';
        $store = new MigrationStore($this->workDir . '/migrations');

        file_put_contents($schemaPath, <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}
generator client { output = "./gen" namespace = "X\\Gen" }
model User {
  id    Int    @id @default(autoincrement())
  email String @unique
}
TXT);

        $driver = Drivers::forPdo(Config::pdo('sqlite:' . $this->dbPath));
        $migrator = new Migrator($driver, $store, $schemaPath);

        $r1 = $migrator->dev('init');
        self::assertFalse($r1['skipped']);
        self::assertSame(1, $r1['statements']);

        $check = $driver->pdo()->prepare('SELECT email FROM "User"');
        $check->execute();
        self::assertSame([], $check->fetchAll());

        // v2: add `name` column
        file_put_contents($schemaPath, <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}
generator client { output = "./gen" namespace = "X\\Gen" }
model User {
  id    Int     @id @default(autoincrement())
  email String  @unique
  name  String?
}
TXT);

        $r2 = $migrator->dev('add_name');
        self::assertFalse($r2['skipped']);
        $sql = file_get_contents($r2['path']) ?: '';
        self::assertStringContainsString('ADD COLUMN', $sql);
        self::assertStringContainsString('"name"', $sql);

        // No further change → skipped
        $r3 = $migrator->dev('noop');
        self::assertTrue($r3['skipped']);

        $status = $migrator->status();
        self::assertCount(2, $status);
        self::assertTrue($status[0]['applied']);
        self::assertTrue($status[1]['applied']);

        $insert = $driver->pdo()->prepare('INSERT INTO "User" (email, name) VALUES (?, ?)');
        $insert->execute(['a@x', 'Alice']);
        $select = $driver->pdo()->prepare('SELECT name FROM "User" WHERE email = ?');
        $select->execute(['a@x']);
        self::assertSame('Alice', $select->fetchColumn());
    }

    public function testDeployAppliesPendingMigrationsAcrossDatabases(): void
    {
        $schemaPath = $this->workDir . '/schema.tehilim';
        $store = new MigrationStore($this->workDir . '/migrations');

        file_put_contents($schemaPath, <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}
generator client { output = "./gen" namespace = "X\\Gen" }
model Item {
  id   Int    @id @default(autoincrement())
  name String
}
TXT);

        $driverA = Drivers::forPdo(Config::pdo('sqlite:' . $this->dbPath));
        $migratorA = new Migrator($driverA, $store, $schemaPath);
        $migratorA->dev('init');

        $otherDb = $this->workDir . '/prod.sqlite';
        $driverB = Drivers::forPdo(Config::pdo('sqlite:' . $otherDb));
        $migratorB = new Migrator($driverB, $store, $schemaPath);
        $applied = $migratorB->deploy();
        self::assertCount(1, $applied);

        self::assertSame([], $migratorB->deploy(), 'second deploy is a no-op');

        $ins = $driverB->pdo()->prepare('INSERT INTO "Item" (name) VALUES (?)');
        $ins->execute(['Widget']);

        $sel = $driverB->pdo()->prepare('SELECT COUNT(*) FROM "Item"');
        $sel->execute();
        self::assertSame(1, (int) $sel->fetchColumn());
    }

    public function testSchemaDiffEmitsCreateAndDrop(): void
    {
        $from = new SchemaAst();
        $to = Parser::parseString(<<<'TXT'
model Note {
  id   Int    @id @default(autoincrement())
  text String
}
TXT);

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        $stmts = (new SchemaDiff())->diff($from, $to, $driver);
        self::assertCount(1, $stmts);
        self::assertStringContainsString('CREATE TABLE', $stmts[0]);

        $reverse = (new SchemaDiff())->diff($to, $from, $driver);
        self::assertCount(1, $reverse);
        self::assertStringContainsString('DROP TABLE', $reverse[0]);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }
}
