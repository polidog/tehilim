<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class ManyToManyTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-m2m-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testImplicitManyToManyEndToEnd(): void
    {
        [$db, $pdo] = $this->makeBlog('M2MEnd');

        $php  = $db->tag->insert(['data' => ['name' => 'php']]);
        $ruby = $db->tag->insert(['data' => ['name' => 'ruby']]);

        $hello = $db->post->insert(['data' => [
            'title' => 'Hello',
            'tags'  => ['connect' => [['id' => $php['id']], ['id' => $ruby['id']]]],
        ]]);

        $stmt = $pdo->prepare('SELECT "A", "B" FROM "_PostToTag" ORDER BY "A", "B"');
        $stmt->execute();
        self::assertCount(2, $stmt->fetchAll());

        // include from Post side (alphabetically first = column A)
        $posts = $db->post->findMany(['include' => ['tags' => true]]);
        self::assertCount(1, $posts);
        $tagNames = array_column($posts[0]['tags'], 'name');
        sort($tagNames);
        self::assertSame(['php', 'ruby'], $tagNames);

        // include from Tag side (alphabetically second = column B)
        $tagsWithPosts = $db->tag->findMany([
            'include' => ['posts' => true],
            'orderBy' => ['id' => 'asc'],
        ]);
        self::assertSame(['Hello'], array_column($tagsWithPosts[0]['posts'], 'title'));
        self::assertSame(['Hello'], array_column($tagsWithPosts[1]['posts'], 'title'));

        // disconnect one
        $db->post->update([
            'where' => ['id' => $hello['id']],
            'data'  => ['tags' => ['disconnect' => [['id' => $ruby['id']]]]],
        ]);
        $after = $db->post->findUnique([
            'where'   => ['id' => $hello['id']],
            'include' => ['tags' => true],
        ]);
        self::assertNotNull($after);
        self::assertSame(['php'], array_column($after['tags'], 'name'));

        // set: replace all edges
        $go = $db->tag->insert(['data' => ['name' => 'go']]);
        $db->post->update([
            'where' => ['id' => $hello['id']],
            'data'  => ['tags' => ['set' => [['id' => $go['id']]]]],
        ]);
        $after2 = $db->post->findUnique([
            'where'   => ['id' => $hello['id']],
            'include' => ['tags' => true],
        ]);
        self::assertNotNull($after2);
        self::assertSame(['go'], array_column($after2['tags'], 'name'));
    }

    public function testNestedWhereInsideM2MInclude(): void
    {
        [$db] = $this->makeBlog('M2MNested');

        $php  = $db->tag->insert(['data' => ['name' => 'php']]);
        $ruby = $db->tag->insert(['data' => ['name' => 'ruby']]);

        $db->post->insert(['data' => [
            'title' => 'A',
            'tags'  => ['connect' => [['id' => $php['id']], ['id' => $ruby['id']]]],
        ]]);

        $posts = $db->post->findMany([
            'include' => ['tags' => ['where' => ['name' => 'php']]],
        ]);
        self::assertSame(['php'], array_column($posts[0]['tags'], 'name'));
    }

    public function testJoinTableIsDroppedWhenM2MRemoved(): void
    {
        // Push a schema with M2M, then push a schema without it; the join table should be gone.
        $withM2M = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "M2MDrop\\Gen" }

model Post { id Int @id @default(autoincrement()) title String tags Tag[] }
model Tag  { id Int @id @default(autoincrement()) name  String posts Post[] }
TXT);

        $pdo = Config::pdo('sqlite::memory:');
        $driver = Drivers::forPdo($pdo);
        (new SchemaSync($driver, $withM2M))->push();

        $exists = static function () use ($pdo): mixed {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='_PostToTag'");
            $stmt->execute();
            $v = $stmt->fetchColumn();
            $stmt->closeCursor();
            return $v;
        };
        self::assertSame('_PostToTag', $exists());

        $withoutM2M = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "M2MDrop\\Gen" }

model Post { id Int @id @default(autoincrement()) title String }
model Tag  { id Int @id @default(autoincrement()) name  String }
TXT);

        (new SchemaSync($driver, $withoutM2M))->push();
        self::assertFalse($exists());
    }

    /**
     * @return array{0: object, 1: \PDO}
     */
    private function makeBlog(string $ns): array
    {
        $schema = Parser::parseString(<<<TXT
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "{$ns}\\\\Gen" }

model Post {
  id    Int    @id @default(autoincrement())
  title String
  tags  Tag[]
}

model Tag {
  id    Int    @id @default(autoincrement())
  name  String @unique
  posts Post[]
}
TXT);

        $outDir = $this->workDir . '/gen-' . strtolower($ns);
        (new Generator($schema, $outDir, $ns . '\\Gen'))->generate();
        require_once $outDir . '/Model/Post.php';
        require_once $outDir . '/Model/Tag.php';
        require_once $outDir . '/TehilimClient.php';

        $pdo = Config::pdo('sqlite::memory:');
        $driver = Drivers::forPdo($pdo);
        (new SchemaSync($driver, $schema))->push();

        $clientClass = $ns . '\\Gen\\TehilimClient';
        return [new $clientClass($driver), $pdo];
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
