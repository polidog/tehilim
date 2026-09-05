<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Client\BaseClient;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;
use RuntimeException;

final class RawQueryTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-raw-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testShapeCastsAndReducesRows(): void
    {
        [$db] = $this->makeClient('RawShapeCast');
        $db->user->insert(['data' => ['email' => 'a@x', 'name' => 'Alice', 'active' => true]]);
        $db->user->insert(['data' => ['email' => 'b@x', 'active' => false]]);

        $rows = $db->queryRaw(
            'SELECT u.id, u.name, u.active, u."createdAt", (SELECT COUNT(*) FROM "User") AS total, u.email FROM "User" u ORDER BY u.id',
            [],
            ['id' => 'int', 'name' => '?string', 'active' => 'bool', 'createdAt' => 'DateTime', 'total' => 'int'],
        );

        self::assertCount(2, $rows);
        self::assertSame(['id', 'name', 'active', 'createdAt', 'total'], array_keys($rows[0]), 'email is not in the shape and must be dropped');
        self::assertIsInt($rows[0]['id']);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertNull($rows[1]['name']);
        self::assertTrue($rows[0]['active']);
        self::assertFalse($rows[1]['active']);
        self::assertInstanceOf(DateTimeImmutable::class, $rows[0]['createdAt']);
        self::assertSame(2, $rows[0]['total']);
    }

    public function testWithoutShapeRowsComeBackAsFetched(): void
    {
        [$db] = $this->makeClient('RawNoShape');
        $db->user->insert(['data' => ['email' => 'a@x', 'active' => true]]);

        $rows = $db->queryRaw('SELECT email, active FROM "User"');

        self::assertSame([['email' => 'a@x', 'active' => 1]], $rows);
    }

    public function testMissingShapeColumnThrows(): void
    {
        [$db] = $this->makeClient('RawMissing');
        $db->user->insert(['data' => ['email' => 'a@x']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("shape lists column 'nope'");
        $db->queryRaw('SELECT id FROM "User"', [], ['id' => 'int', 'nope' => 'string']);
    }

    public function testUnknownTagThrowsBeforeExecuting(): void
    {
        [$db] = $this->makeClient('RawBadTag');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown tehilim raw type tag 'itn' for column 'id'");
        $db->queryRaw('SELECT * FROM "NoSuchTable"', [], ['id' => 'itn']);
    }

    public function testPositionalAndNamedParamsWithBoolAndDateTimeNormalization(): void
    {
        [$db] = $this->makeClient('RawParams');
        $db->user->insert(['data' => ['email' => 'a@x', 'active' => true]]);
        $db->user->insert(['data' => ['email' => 'b@x', 'active' => false]]);

        $positional = $db->queryRaw('SELECT email FROM "User" WHERE active = ?', [true], ['email' => 'string']);
        self::assertSame([['email' => 'a@x']], $positional);

        $named = $db->queryRaw('SELECT email FROM "User" WHERE email = :email', ['email' => 'b@x'], ['email' => 'string']);
        self::assertSame([['email' => 'b@x']], $named);

        $future = $db->queryRaw(
            'SELECT COUNT(*) AS n FROM "User" WHERE "createdAt" > ?',
            [new DateTimeImmutable('+1 day')],
            ['n' => 'int'],
        );
        self::assertSame([['n' => 0]], $future);
    }

    public function testExecuteRawReturnsAffectedRowsAndFlushesCache(): void
    {
        [$db] = $this->makeClient('RawExecute');
        $db->user->insert(['data' => ['email' => 'a@x', 'name' => 'before']]);
        $db->user->insert(['data' => ['email' => 'b@x', 'name' => 'before']]);

        $cached = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($cached);
        self::assertSame('before', $cached['name']);

        $affected = $db->executeRaw('UPDATE "User" SET name = ? WHERE name = ?', ['after', 'before']);
        self::assertSame(2, $affected);

        $fresh = $db->user->cached()->findUnique(['where' => ['email' => 'a@x']]);
        self::assertNotNull($fresh);
        self::assertSame('after', $fresh['name'], 'executeRaw must flush the request cache');
    }

    public function testModelQueryRawCastsKnownColumnsAndPassesOthersThrough(): void
    {
        [$db] = $this->makeClient('RawModel');
        $db->user->insert(['data' => ['email' => 'a@x', 'name' => 'Alice', 'active' => true]]);
        $db->user->insert(['data' => ['email' => 'b@x', 'name' => 'Bob', 'active' => false]]);

        $rows = $db->user->queryRaw('SELECT *, length(email) AS len FROM "User" WHERE name LIKE ? ORDER BY id', ['A%']);

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertTrue($rows[0]['active']);
        self::assertInstanceOf(DateTimeImmutable::class, $rows[0]['createdAt']);
        self::assertSame(3, $rows[0]['len'], 'unknown columns pass through as fetched');
    }

    public function testRawCallsReportToProfilerWithTheSqlAsLabel(): void
    {
        [$db] = $this->makeClient('RawProfiler');
        /** @var list<array{0: string, 1: string}> $events */
        $events = [];
        $db->withProfiler(function (string $collector, string $label, callable $fn) use (&$events): mixed {
            $events[] = [$collector, $label];

            return $fn();
        });

        $db->executeRaw('INSERT INTO "User" (email) VALUES (?)', ['a@x']);
        $db->queryRaw('SELECT id FROM "User"', [], ['id' => 'int']);
        $db->user->queryRaw('SELECT * FROM "User"');

        self::assertSame([
            ['tehilim.executeRaw', 'INSERT INTO "User" (email) VALUES (?)'],
            ['tehilim.queryRaw', 'SELECT id FROM "User"'],
            ['tehilim.queryRaw', 'User'],
        ], $events);
    }

    /** @return array{0: BaseClient, 1: PDO} */
    private function makeClient(string $ns): array
    {
        $schema = Parser::parseString(<<<TXT
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "{$ns}\\\\Gen" }

model User {
  id        Int      @id @default(autoincrement())
  email     String   @unique
  name      String?
  active    Boolean  @default(true)
  createdAt DateTime @default(now())
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
        /** @var BaseClient $client */
        $client = new $clientClass($driver);

        return [$client, $pdo];
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
