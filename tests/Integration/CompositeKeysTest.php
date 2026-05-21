<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;

final class CompositeKeysTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-comp-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testCompositePrimaryKey(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "CompPk\\Gen" }

model Enrollment {
  userId   Int
  courseId Int
  grade    String?

  @@id([userId, courseId])
}
TXT);

        $outDir = $this->workDir . '/gen';
        (new Generator($schema, $outDir, 'CompPk\\Gen'))->generate();
        require $outDir . '/Model/Enrollment.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push();

        $clientClass = 'CompPk\\Gen\\TehilimClient';
        /** @var \Polidog\Tehilim\Client\BaseClient $db */
        $db = new $clientClass($driver);

        $db->enrollment->insert(['data' => ['userId' => 1, 'courseId' => 100, 'grade' => 'A']]);
        $db->enrollment->insert(['data' => ['userId' => 1, 'courseId' => 101, 'grade' => 'B']]);
        $db->enrollment->insert(['data' => ['userId' => 2, 'courseId' => 100, 'grade' => 'C']]);

        // PK collision rejected
        $threw = false;
        try {
            $db->enrollment->insert(['data' => ['userId' => 1, 'courseId' => 100, 'grade' => 'D']]);
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'composite PK should reject duplicates');

        // Lookup by composite key
        $row = $db->enrollment->findUnique(['where' => ['userId' => 1, 'courseId' => 101]]);
        self::assertNotNull($row);
        self::assertSame('B', $row['grade']);

        // Update by composite key
        $up = $db->enrollment->update([
            'where' => ['userId' => 1, 'courseId' => 100],
            'data'  => ['grade' => 'A+'],
        ]);
        self::assertSame('A+', $up['grade']);

        self::assertSame(3, $db->enrollment->count());
    }

    public function testCompositeUnique(): void
    {
        $schema = Parser::parseString(<<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }
generator client { output = "./gen" namespace = "CompUq\\Gen" }

model Member {
  id       Int    @id @default(autoincrement())
  tenantId Int
  email    String

  @@unique([tenantId, email])
}
TXT);

        $outDir = $this->workDir . '/gen';
        (new Generator($schema, $outDir, 'CompUq\\Gen'))->generate();
        require $outDir . '/Model/Member.php';
        require $outDir . '/TehilimClient.php';

        $driver = Drivers::forPdo(Config::pdo('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push();

        $clientClass = 'CompUq\\Gen\\TehilimClient';
        /** @var \Polidog\Tehilim\Client\BaseClient $db */
        $db = new $clientClass($driver);

        $db->member->insert(['data' => ['tenantId' => 1, 'email' => 'a@x']]);
        $db->member->insert(['data' => ['tenantId' => 2, 'email' => 'a@x']]); // ok: different tenant

        $threw = false;
        try {
            $db->member->insert(['data' => ['tenantId' => 1, 'email' => 'a@x']]);
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'composite unique should reject');

        $row = $db->member->findUnique(['where' => ['tenantId' => 1, 'email' => 'a@x']]);
        self::assertNotNull($row);
        self::assertSame(1, $row['tenantId']);
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
