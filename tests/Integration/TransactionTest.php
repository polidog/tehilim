<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Client\Rollback;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class TransactionTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-tx-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testCommitsAndReturnsValue(): void
    {
        $db = $this->makeClient('Tx1');

        $created = $db->transaction(function ($tx) {
            $u = $tx->user->insert(['data' => ['email' => 'a@x']]);
            $tx->user->insert(['data' => ['email' => 'b@x']]);
            return $u;
        });

        self::assertSame('a@x', $created['email']);
        self::assertSame(2, $db->user->count());
    }

    public function testRollsBackOnException(): void
    {
        $db = $this->makeClient('Tx2');

        $threw = false;
        try {
            $db->transaction(function ($tx) {
                $tx->user->insert(['data' => ['email' => 'a@x']]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $threw = true;
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue($threw);
        self::assertSame(0, $db->user->count(), 'failed transaction should leave no rows');
    }

    public function testExplicitRollbackReturnsPayload(): void
    {
        $db = $this->makeClient('Tx3');

        $result = $db->transaction(function ($tx) {
            $tx->user->insert(['data' => ['email' => 'a@x']]);
            throw new Rollback('discarded');
        });

        self::assertSame('discarded', $result);
        self::assertSame(0, $db->user->count());
    }

    public function testNestedSavepointRollbackPreservesOuter(): void
    {
        $db = $this->makeClient('Tx4');

        $db->transaction(function ($tx) {
            $tx->user->insert(['data' => ['email' => 'outer@x']]);

            try {
                $tx->transaction(function ($tx2) {
                    $tx2->user->insert(['data' => ['email' => 'inner@x']]);
                    throw new \RuntimeException('inner fail');
                });
            } catch (\RuntimeException) {
                // inner rolled back, outer keeps going
            }

            $tx->user->insert(['data' => ['email' => 'after@x']]);
        });

        $emails = array_column(
            $db->user->findMany(['orderBy' => ['id' => 'asc']]),
            'email',
        );
        self::assertSame(['outer@x', 'after@x'], $emails);
    }

    public function testNestedSavepointCommitsOnSuccess(): void
    {
        $db = $this->makeClient('Tx5');

        $db->transaction(function ($tx) {
            $tx->user->insert(['data' => ['email' => 'outer@x']]);
            $tx->transaction(function ($tx2) {
                $tx2->user->insert(['data' => ['email' => 'inner@x']]);
            });
        });

        self::assertSame(2, $db->user->count());
    }

    private function makeClient(string $ns): object
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
