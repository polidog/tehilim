<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Cli\Application;

final class MigrateCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-migrate-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->workDir);
    }

    public function testResetWithoutForceIsRejected(): void
    {
        $schemaPath = $this->workDir . '/schema.tehilim';
        file_put_contents($schemaPath, <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite:dev.sqlite"
}

model Note {
  id Int @id @default(autoincrement())
}
TXT);

        $app = new Application();

        ob_start();
        $code = $app->run(['tehilim', 'migrate', 'reset', '--schema', $schemaPath]);
        ob_end_clean();

        // The guard returns before any DB work, so no database is created.
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->workDir . '/dev.sqlite');
    }
}
