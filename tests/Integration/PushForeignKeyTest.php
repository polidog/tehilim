<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\SqliteDriver;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Migration\TableBuilder;
use Polidog\Tehilim\Schema\Parser;

/**
 * `push` (and the DDL builder) now emit FOREIGN KEY constraints from relations.
 * Verified at the DDL level + by reading the live SQLite schema back with
 * pragma_foreign_key_list (independent of the `pull` introspection path).
 */
final class PushForeignKeyTest extends TestCase
{
    public function testCreateTableSqlEmitsForeignKey(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model User {
  id    Int    @id @default(autoincrement())
  posts Post[]
}

model Post {
  id       Int  @id @default(autoincrement())
  authorId Int
  author   User @relation(fields: [authorId], references: [id])
}
TXT);

        $driver = new SqliteDriver(new PDO('sqlite::memory:'));
        $tables = TableBuilder::fromSchema($schema);
        $post = $this->table($tables, 'Post');

        $sql = $driver->createTableSql($post);
        self::assertStringContainsString('FOREIGN KEY ("authorId") REFERENCES "User" ("id")', $sql);
    }

    public function testPushCreatesForeignKeyInDatabase(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  posts Post[]
}

model Post {
  id       Int    @id @default(autoincrement())
  title    String
  authorId Int
  author   User   @relation(fields: [authorId], references: [id])
}
TXT);

        $pdo = new PDO('sqlite::memory:');
        (new SchemaSync(new SqliteDriver($pdo), $schema))->push(drop: true);

        $fks = $this->liveForeignKeys($pdo, 'Post');
        self::assertCount(1, $fks);
        self::assertSame(['table' => 'User', 'from' => 'authorId', 'to' => 'id'], $fks[0]);
    }

    public function testForeignKeyUsesMappedColumnNames(): void
    {
        // Both the FK-holding field and the referenced PK use @map, so the FK
        // must reference the *column* names, not the schema field names.
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model User {
  id    Int    @id @default(autoincrement()) @map("user_id")
  posts Post[]
}

model Post {
  id       Int  @id @default(autoincrement())
  authorId Int  @map("author_id")
  author   User @relation(fields: [authorId], references: [id])
}
TXT);

        $driver = new SqliteDriver(new PDO('sqlite::memory:'));
        $tables = TableBuilder::fromSchema($schema);
        $post = $this->table($tables, 'Post');

        $sql = $driver->createTableSql($post);
        self::assertStringContainsString('FOREIGN KEY ("author_id") REFERENCES "User" ("user_id")', $sql);

        $pdo = new PDO('sqlite::memory:');
        (new SchemaSync(new SqliteDriver($pdo), $schema))->push(drop: true);

        $fks = $this->liveForeignKeys($pdo, 'Post');
        self::assertSame(['table' => 'User', 'from' => 'author_id', 'to' => 'user_id'], $fks[0]);
    }

    public function testPushDropsLeftoverTablesNotInSchema(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model User {
  id Int @id @default(autoincrement())
}
TXT);

        $pdo = new PDO('sqlite::memory:');
        $this->runSql($pdo, 'CREATE TABLE "User" ("id" INTEGER PRIMARY KEY)');
        // A leftover table holding an FK back into a schema table; on
        // MySQL/PostgreSQL this must be dropped before "User".
        $this->runSql($pdo, 'CREATE TABLE "Legacy" ("id" INTEGER PRIMARY KEY, "uid" INTEGER REFERENCES "User" ("id"))');

        (new SchemaSync(new SqliteDriver($pdo), $schema))->push(drop: true);

        $tables = $this->liveTables($pdo);
        self::assertContains('User', $tables);
        self::assertNotContains('Legacy', $tables);
    }

    public function testPushCreatesJoinTableForeignKeys(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model Post {
  id   Int   @id @default(autoincrement())
  tags Tag[]
}

model Tag {
  id    Int    @id @default(autoincrement())
  posts Post[]
}
TXT);

        $pdo = new PDO('sqlite::memory:');
        (new SchemaSync(new SqliteDriver($pdo), $schema))->push(drop: true);

        $refs = [];
        foreach ($this->liveForeignKeys($pdo, '_PostToTag') as $fk) {
            $refs[$fk['from']] = $fk['table'];
        }
        ksort($refs);
        self::assertSame(['A' => 'Post', 'B' => 'Tag'], $refs);
    }

    public function testCreateOrderPutsReferencedTableFirst(): void
    {
        // Post references User; even though Post is declared first, the builder
        // must order User before Post so the FK target exists at CREATE time.
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model Post {
  id       Int  @id @default(autoincrement())
  authorId Int
  author   User @relation(fields: [authorId], references: [id])
}

model User {
  id    Int    @id @default(autoincrement())
  posts Post[]
}
TXT);

        $order = array_map(static fn ($t) => $t->name, TableBuilder::fromSchema($schema));
        self::assertLessThan(
            array_search('Post', $order, true),
            array_search('User', $order, true),
            'User must be ordered before Post',
        );
    }

    /**
     * @param list<\Polidog\Tehilim\Migration\TableDef> $tables
     */
    private function table(array $tables, string $name): \Polidog\Tehilim\Migration\TableDef
    {
        foreach ($tables as $t) {
            if ($t->name === $name) {
                return $t;
            }
        }
        self::fail("table {$name} not built");
    }

    private function runSql(PDO $pdo, string $sql): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    /**
     * @return list<string>
     */
    private function liveTables(PDO $pdo): array
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $stmt->execute();
        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    /**
     * @return list<array{table:string,from:string,to:string}>
     */
    private function liveForeignKeys(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT "table", "from", "to" FROM pragma_foreign_key_list(?)');
        $stmt->execute([$table]);
        /** @var list<array{table:string,from:string,to:string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
