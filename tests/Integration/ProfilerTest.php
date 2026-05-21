<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class ProfilerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-prof-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testProfilerCapturesEachOperation(): void
    {
        [$db] = $this->makeClient('Prof1');

        /** @var list<array{collector: string, label: string, durationMs: float}> $events */
        $events = [];
        $db->withProfiler(function (string $collector, string $label, callable $fn) use (&$events) {
            $start = hrtime(true);
            try {
                return $fn();
            } finally {
                $events[] = [
                    'collector'  => $collector,
                    'label'      => $label,
                    'durationMs' => (hrtime(true) - $start) / 1_000_000,
                ];
            }
        });

        $db->user->insert(['data' => ['email' => 'a@x']]);
        $db->user->findMany();
        $db->user->count();

        $collectors = array_column($events, 'collector');
        self::assertContains('tehilim.insert', $collectors);
        self::assertContains('tehilim.findMany', $collectors);
        self::assertContains('tehilim.count', $collectors);
        self::assertSame(['User'], array_values(array_unique(array_column($events, 'label'))));
        foreach ($events as $e) {
            self::assertGreaterThan(0.0, $e['durationMs']);
        }
    }

    public function testProfilerNestsForUpsert(): void
    {
        [$db] = $this->makeClient('Prof2');

        /** @var list<string> $collectors */
        $collectors = [];
        $db->withProfiler(function (string $collector, string $label, callable $fn) use (&$collectors) {
            $collectors[] = $collector;
            return $fn();
        });

        $db->user->upsert([
            'where'  => ['email' => 'a@x'],
            'insert' => ['email' => 'a@x'],
            'update' => ['email' => 'a@x'],
        ]);

        // upsert ran, no existing row → triggered findFirst (which ran findMany) then insert
        self::assertSame(
            ['tehilim.upsert', 'tehilim.findFirst', 'tehilim.findMany', 'tehilim.insert'],
            $collectors,
        );
    }

    public function testProfilerCanBeCleared(): void
    {
        [$db] = $this->makeClient('Prof3');

        $count = 0;
        $db->withProfiler(function (string $c, string $l, callable $fn) use (&$count) {
            $count++;
            return $fn();
        });

        $db->user->insert(['data' => ['email' => 'a@x']]);
        self::assertSame(1, $count);

        $db->withProfiler(null);
        $db->user->insert(['data' => ['email' => 'b@x']]);
        self::assertSame(1, $count, 'cleared profiler should not be called');
    }

    public function testCacheHitsSkipProfiler(): void
    {
        [$db] = $this->makeClient('Prof4');
        $db->enableCache();

        $events = 0;
        $db->withProfiler(function (string $c, string $l, callable $fn) use (&$events) {
            $events++;
            return $fn();
        });

        $db->user->insert(['data' => ['email' => 'a@x']]);
        $eventsAfterInsert = $events;

        $db->user->findMany();              // miss → profile fires
        $db->user->findMany();              // hit  → profile skipped
        $db->user->findMany();              // hit  → profile skipped

        // We expect: insert + first findMany + (nested findFirst/findMany inside insert? no — leaf)
        // = exactly one new event from the miss; the two hits should not increment.
        self::assertSame($eventsAfterInsert + 1, $events);
    }

    /**
     * @return array{0: object}
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
        require_once $outDir . '/Model/UserClient.php';
        require_once $outDir . '/TehilimClient.php';

        $pdo = Config::pdo('sqlite::memory:');
        $driver = Drivers::forPdo($pdo);
        (new SchemaSync($driver, $schema))->push();

        $clientClass = $ns . '\\Gen\\TehilimClient';
        return [new $clientClass($driver)];
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
