<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class RelationsIncludeTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-rel-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testIncludeLoadsBelongsToAndHasMany(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite::memory:"
}

generator client {
  output    = "./gen"
  namespace = "TestRel\\Gen"
}

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

        $outDir = $this->workDir . '/gen';
        (new Generator($schema, $outDir, 'TestRel\\Gen'))->generate();

        require $outDir . '/Model/UserClient.php';
        require $outDir . '/Model/PostClient.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push();

        $clientClass = 'TestRel\\Gen\\TehilimClient';
        /** @var \Polidog\Tehilim\Client\BaseClient $db */
        $db = new $clientClass($driver);

        $alice = $db->user->insert(['data' => ['email' => 'a@x']]);
        $bob   = $db->user->insert(['data' => ['email' => 'b@x']]);

        $db->post->insert(['data' => ['title' => 'A1', 'authorId' => $alice['id']]]);
        $db->post->insert(['data' => ['title' => 'A2', 'authorId' => $alice['id']]]);
        $db->post->insert(['data' => ['title' => 'B1', 'authorId' => $bob['id']]]);

        // hasMany include
        $users = $db->user->findMany([
            'include' => ['posts' => true],
            'orderBy' => ['id' => 'asc'],
        ]);
        self::assertCount(2, $users);
        self::assertSame(['A1', 'A2'], array_column($users[0]['posts'], 'title'));
        self::assertSame(['B1'],       array_column($users[1]['posts'], 'title'));

        // belongsTo include
        $posts = $db->post->findMany([
            'include' => ['author' => true],
            'orderBy' => ['id' => 'asc'],
        ]);
        self::assertCount(3, $posts);
        self::assertSame('a@x', $posts[0]['author']['email']);
        self::assertSame('a@x', $posts[1]['author']['email']);
        self::assertSame('b@x', $posts[2]['author']['email']);

        // nested where inside include
        $usersFiltered = $db->user->findMany([
            'include' => ['posts' => ['where' => ['title' => 'A2']]],
            'orderBy' => ['id' => 'asc'],
        ]);
        self::assertSame(['A2'], array_column($usersFiltered[0]['posts'], 'title'));
        self::assertSame([],     $usersFiltered[1]['posts']);
    }

    public function testSelectProjectsSubsetOfColumns(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite::memory:"
}

generator client {
  output    = "./gen"
  namespace = "TestSel\\Gen"
}

model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  name  String?
  age   Int?
}
TXT);

        $outDir = $this->workDir . '/gen';
        (new Generator($schema, $outDir, 'TestSel\\Gen'))->generate();

        require $outDir . '/Model/UserClient.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push();

        $clientClass = 'TestSel\\Gen\\TehilimClient';
        /** @var \Polidog\Tehilim\Client\BaseClient $db */
        $db = new $clientClass($driver);

        $db->user->insert(['data' => ['email' => 'a@x', 'name' => 'A', 'age' => 30]]);
        $db->user->insert(['data' => ['email' => 'b@x', 'name' => 'B', 'age' => 40]]);

        $rows = $db->user->findMany([
            'select'  => ['email' => true],
            'orderBy' => ['id' => 'asc'],
        ]);
        self::assertSame(['email' => 'a@x', 'id' => 1], array_intersect_key($rows[0], ['email' => 1, 'id' => 1]));
        self::assertArrayNotHasKey('name', $rows[0]);
        self::assertArrayNotHasKey('age',  $rows[0]);
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
