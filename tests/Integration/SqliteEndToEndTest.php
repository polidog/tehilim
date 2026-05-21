<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class SqliteEndToEndTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testGenerateAndQueryAgainstSqlite(): void
    {
        $schemaSrc = <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite::memory:"
}

generator client {
  output    = "./gen"
  namespace = "Test\\Gen"
}

model User {
  id    Int     @id @default(autoincrement())
  email String  @unique
  name  String?
  age   Int?
}
TXT;

        $schema = Parser::parseString($schemaSrc);

        $outDir = $this->workDir . '/gen';
        (new Generator($schema, $outDir, 'Test\\Gen'))->generate();

        self::assertFileExists($outDir . '/TehilimClient.php');
        self::assertFileExists($outDir . '/Model/UserClient.php');

        require $outDir . '/Model/UserClient.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push(drop: true);

        $clientClass = 'Test\\Gen\\TehilimClient';
        /** @var \Polidog\Tehilim\Client\BaseClient $db */
        $db = new $clientClass($driver);

        $alice = $db->user->insert(['data' => ['email' => 'a@b.com', 'name' => 'Alice', 'age' => 30]]);
        self::assertIsInt($alice['id']);
        self::assertSame('a@b.com', $alice['email']);

        $bob = $db->user->insert(['data' => ['email' => 'b@b.com', 'name' => 'Bob']]);
        self::assertIsInt($bob['id']);

        $found = $db->user->findUnique(['where' => ['email' => 'a@b.com']]);
        self::assertNotNull($found);
        self::assertSame('Alice', $found['name']);

        $all = $db->user->findMany(['orderBy' => ['id' => 'asc']]);
        self::assertCount(2, $all);

        $young = $db->user->findMany(['where' => ['age' => ['lt' => 40]]]);
        self::assertCount(1, $young);

        $updated = $db->user->update([
            'where' => ['id' => $alice['id']],
            'data'  => ['name' => 'Alice Cooper'],
        ]);
        self::assertSame('Alice Cooper', $updated['name']);

        $count = $db->user->count();
        self::assertSame(2, $count);

        $deleted = $db->user->delete(['where' => ['id' => $bob['id']]]);
        self::assertSame('b@b.com', $deleted['email']);

        self::assertSame(1, $db->user->count());
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
