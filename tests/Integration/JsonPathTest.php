<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class JsonPathTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-json-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testJsonPathFilters(): void
    {
        $db = $this->makeClient('Json1');

        $db->doc->insert(['data' => ['profile' => [
            'address' => ['city' => 'Tokyo'],
            'tags' => ['php', 'sql'],
        ]]]);
        $db->doc->insert(['data' => ['profile' => [
            'address' => ['city' => 'Osaka'],
            'tags' => ['go'],
        ]]]);

        $cities = fn (array $rows): array => array_map(
            static fn (array $r): string => $r['profile']['address']['city'],
            $rows,
        );

        // equals on a nested scalar
        $rows = $db->doc->findMany(['where' => [
            'profile' => ['path' => ['address', 'city'], 'equals' => 'Tokyo'],
        ]]);
        self::assertSame(['Tokyo'], $cities($rows));

        // string_contains on a nested scalar
        $rows = $db->doc->findMany(['where' => [
            'profile' => ['path' => ['address', 'city'], 'string_contains' => 'saka'],
        ]]);
        self::assertSame(['Osaka'], $cities($rows));

        // string_starts_with
        $rows = $db->doc->findMany(['where' => [
            'profile' => ['path' => ['address', 'city'], 'string_starts_with' => 'To'],
        ]]);
        self::assertSame(['Tokyo'], $cities($rows));

        // array_contains on a nested array
        $rows = $db->doc->findMany(['where' => [
            'profile' => ['path' => ['tags'], 'array_contains' => 'php'],
        ]]);
        self::assertSame(['Tokyo'], $cities($rows));

        // no match
        $rows = $db->doc->findMany(['where' => [
            'profile' => ['path' => ['address', 'city'], 'equals' => 'Kyoto'],
        ]]);
        self::assertSame([], $rows);

        // combined with a normal column filter via AND
        self::assertSame(2, $db->doc->count());
    }

    private function makeClient(string $ns): object
    {
        $schema = Parser::parseString(<<<TXT
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "{$ns}\\\\Gen" }

model Doc {
  id      Int  @id @default(autoincrement())
  profile Json
}
TXT);

        $outDir = $this->workDir . '/gen-' . strtolower($ns);
        (new Generator($schema, $outDir, $ns . '\\Gen'))->generate();
        require $outDir . '/Model/Doc.php';
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
