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

    public function testCachedChainServesIdenticalReadsFromMemory(): void
    {
        [$db, $pdo] = $this->makeClient('CacheHit');

        $db->user->insert(['data' => ['email' => 'a@x']]);

        $first = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($first);

        // Mutate the row out-of-band; the cache must keep returning the old value.
        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $first['id']]);

        $second = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($second);
        self::assertSame('a@x', $second['email'], 'should still be the cached value');

        self::assertSame(1, $db->cache()->hits());
        self::assertSame(1, $db->cache()->misses());
    }

    public function testCallsWithoutCachedSkipTheCacheEntirely(): void
    {
        [$db, $pdo] = $this->makeClient('CacheSkip');

        $db->user->insert(['data' => ['email' => 'a@x']]);
        $row = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);

        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $row['id']]);

        $second = $db->user->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNull($second, 'plain findUnique must always go to the DB');

        // Plain reads do not touch the cache at all.
        self::assertSame(0, $db->cache()->hits());
        self::assertSame(0, $db->cache()->misses());
        self::assertSame(0, $db->cache()->writes());
    }

    public function testWriteFlushesEntriesAcrossCachedClones(): void
    {
        [$db] = $this->makeClient('CacheWrite');

        $db->user->insert(['data' => ['email' => 'a@x']]);

        // Seed two distinct entries on a cached() clone so we can observe
        // whether the post-write reads come back as misses.
        $cached = $db->user->cached();
        $row = $cached->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);
        self::assertSame(1, $cached->count());

        // Confirm both entries are live: re-issuing the same calls hits the cache.
        $cached->findUnique(['where' => ['email' => 'a@x']]);
        $cached->count();
        self::assertSame(2, $db->cache()->hits());
        $missesBeforeWrite = $db->cache()->misses();

        // Any write through the root flushes everything — including entries
        // stored by sibling cached() clones.
        $db->user->insert(['data' => ['email' => 'b@x']]);

        // The two reads must now miss (flush wiped them) and return fresh values.
        self::assertSame(2, $cached->count(), 'count must reflect new row');
        $reloaded = $cached->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($reloaded);
        self::assertSame(
            $missesBeforeWrite + 2,
            $db->cache()->misses(),
            'both previously cached entries should miss after the write flush',
        );
        self::assertSame(2, $db->cache()->hits(), 'no new hits should have happened post-flush');
    }

    public function testDistinctArgsCacheSeparately(): void
    {
        [$db] = $this->makeClient('CacheArgs');

        $db->user->insertMany(['data' => [
            ['email' => 'a@x', 'name' => 'A'],
            ['email' => 'b@x', 'name' => 'B'],
        ]]);

        $cached = $db->user->cached();
        $first  = $cached->findUnique(['where' => ['email' => 'a@x']]);
        $second = $cached->findUnique(['where' => ['email' => 'b@x']]);
        self::assertSame('A', $first['name']);
        self::assertSame('B', $second['name']);

        $cached->findUnique(['where' => ['email' => 'a@x']]);
        $cached->findUnique(['where' => ['email' => 'b@x']]);

        self::assertSame(2, $db->cache()->hits());
    }

    public function testCachedClientIsAliasedToOriginal(): void
    {
        [$db] = $this->makeClient('CacheAlias');

        $db->user->insert(['data' => ['email' => 'a@x']]);

        // Storing once via cached() — a plain read does not see the entry,
        // a second cached() read does.
        $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        $db->user->findUnique(['where' => ['email' => 'a@x']]);
        $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);

        self::assertSame(1, $db->cache()->hits());
        self::assertSame(1, $db->cache()->misses());
    }

    public function testManualFlushDropsAllEntries(): void
    {
        [$db, $pdo] = $this->makeClient('CacheManualFlush');

        $db->user->insert(['data' => ['email' => 'a@x']]);
        $row = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($row);

        $pdo->prepare('UPDATE "User" SET email = ? WHERE id = ?')
            ->execute(['changed@x', $row['id']]);

        $db->flushCache();
        $after = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNull($after, 'flush should make us see the out-of-band change');
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
        require $outDir . '/Model/User.php';
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
