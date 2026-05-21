<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class BulkAndUpsertTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-bulk-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testCreateManyUpdateManyDeleteMany(): void
    {
        $db = $this->makeClient('Bulk');

        $res = $db->user->insertMany(['data' => [
            ['email' => 'a@x', 'name' => 'A', 'active' => true],
            ['email' => 'b@x', 'name' => 'B', 'active' => true],
            ['email' => 'c@x', 'name' => 'C', 'active' => false],
        ]]);
        self::assertSame(3, $res['count']);

        $upd = $db->user->updateMany([
            'where' => ['active' => true],
            'data'  => ['name' => 'updated'],
        ]);
        self::assertSame(2, $upd['count']);

        $rows = $db->user->findMany(['orderBy' => ['id' => 'asc']]);
        self::assertSame(['updated', 'updated', 'C'], array_column($rows, 'name'));

        $del = $db->user->deleteMany(['where' => ['active' => false]]);
        self::assertSame(1, $del['count']);
        self::assertSame(2, $db->user->count());
    }

    public function testCreateManySkipDuplicates(): void
    {
        $db = $this->makeClient('Skip');

        $db->user->insert(['data' => ['email' => 'a@x', 'name' => 'A']]);

        $res = $db->user->insertMany([
            'data' => [
                ['email' => 'a@x', 'name' => 'A again'], // dup
                ['email' => 'b@x', 'name' => 'B'],
            ],
            'skipDuplicates' => true,
        ]);
        self::assertSame(1, $res['count']);
        self::assertSame(2, $db->user->count());
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $db = $this->makeClient('Up');

        $first = $db->user->upsert([
            'where'  => ['email' => 'a@x'],
            'insert' => ['email' => 'a@x', 'name' => 'A1'],
            'update' => ['name' => 'A2'],
        ]);
        self::assertSame('A1', $first['name']);

        $second = $db->user->upsert([
            'where'  => ['email' => 'a@x'],
            'insert' => ['email' => 'a@x', 'name' => 'should not happen'],
            'update' => ['name' => 'A2'],
        ]);
        self::assertSame('A2', $second['name']);
        self::assertSame(1, $db->user->count());
    }

    private function makeClient(string $ns): object
    {
        $schema = Parser::parseString(<<<TXT
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "{$ns}\\\\Gen" }

model User {
  id     Int     @id @default(autoincrement())
  email  String  @unique
  name   String?
  active Boolean @default(true)
}
TXT);

        $outDir = $this->workDir . '/gen-' . strtolower($ns);
        (new Generator($schema, $outDir, $ns . '\\Gen'))->generate();
        require $outDir . '/Model/UserClient.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push();

        $clientClass = $ns . '\\Gen\\TehilimClient';
        return new $clientClass($driver);
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
