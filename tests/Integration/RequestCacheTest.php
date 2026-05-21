<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class RequestCacheTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-cache-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testIdenticalReadsServeFromCache(): void
    {
        [$db, $pdo] = $this->makeClient('CacheHit');
        $db->enableCache();

        $db->user->create(['data' => ['email' => 'a@x']]);

        $first = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($first);

        // Mutate the row out-of-band (cache shouldn't see this if it's serving from memory)
        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $first['id']]);

        $second = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($second);
        self::assertSame('a@x', $second['email'], 'should still be the cached value');

        self::assertSame(1, $db->cache()->hits());
        self::assertSame(1, $db->cache()->misses());
    }

    public function testWriteFlushesCache(): void
    {
        [$db] = $this->makeClient('CacheWrite');
        $db->enableCache();

        $db->user->create(['data' => ['email' => 'a@x']]);
        $beforeCount = $db->user->count();
        self::assertSame(1, $beforeCount);

        // Cache the row
        $row = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);
        $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertSame(1, $db->cache()->hits());

        // Write through the client should flush everything
        $db->user->create(['data' => ['email' => 'b@x']]);

        self::assertSame(2, $db->user->count(), 'count must reflect new row');
    }

    public function testDistinctArgsCacheSeparately(): void
    {
        [$db] = $this->makeClient('CacheArgs');
        $db->enableCache();

        $db->user->createMany(['data' => [
            ['email' => 'a@x', 'name' => 'A'],
            ['email' => 'b@x', 'name' => 'B'],
        ]]);

        $first  = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        $second = $db->user->findUnique(['where' => ['email' => 'b@x']]);
        self::assertSame('A', $first['name']);
        self::assertSame('B', $second['name']);

        // Re-issue the same two reads
        $db->user->findUnique(['where' => ['email' => 'a@x']]);
        $db->user->findUnique(['where' => ['email' => 'b@x']]);

        self::assertSame(2, $db->cache()->hits());
    }

    public function testManualFlushDropsAllEntries(): void
    {
        [$db, $pdo] = $this->makeClient('CacheManualFlush');
        $db->enableCache();

        $db->user->create(['data' => ['email' => 'a@x']]);
        $row = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);

        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $row['id']]);

        $db->flushCache();
        $after = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNull($after, 'flush should make us see the out-of-band change');
    }

    public function testCacheIsOffByDefault(): void
    {
        [$db, $pdo] = $this->makeClient('CacheOff');

        $db->user->create(['data' => ['email' => 'a@x']]);
        $row = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);

        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $row['id']]);

        $second = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNull($second, 'without cache, the update is visible');
        self::assertNull($db->cache());
    }

    /**
     * @return array{0:object, 1:\PDO}
     */
    private function makeClient(string $ns): array
    {
        $schema = Parser::parseString(<<<TXT
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "{$ns}\\\\Gen" }

model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  name  String?
}
TXT);

        $outDir = $this->workDir . '/gen-' . strtolower($ns);
        (new Generator($schema, $outDir, $ns . '\\Gen'))->generate();
        require $outDir . '/Model/UserClient.php';
        require $outDir . '/TehilimClient.php';

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
