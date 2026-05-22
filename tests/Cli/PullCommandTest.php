<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Cli\Application;

final class PullCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/tehilim-pull-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->workDir);
    }

    public function testPushThenPullPrintsSchema(): void
    {
        $schemaPath = $this->workDir . '/schema.tehilim';
        file_put_contents($schemaPath, <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite:dev.sqlite"
}

model Note {
  id    Int    @id @default(autoincrement())
  title String @unique
  body  String?
}
TXT);

        $app = new Application();

        // Create the database from the schema.
        ob_start();
        $pushCode = $app->run(['tehilim', 'push', '--force', '--schema', $schemaPath]);
        ob_end_clean();
        self::assertSame(0, $pushCode);

        // Pull it back to stdout.
        ob_start();
        $pullCode = $app->run(['tehilim', 'pull', '--schema', $schemaPath, '--print']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $pullCode);
        self::assertStringContainsString('datasource db', $out);
        self::assertStringContainsString('model Note {', $out);
        self::assertStringContainsString('id Int @id @default(autoincrement())', $out);
        self::assertStringContainsString('title String @unique', $out);
        self::assertStringContainsString('body String?', $out);
    }

    public function testPushWithoutForceIsRejected(): void
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
        $code = $app->run(['tehilim', 'push', '--schema', $schemaPath]);
        ob_end_clean();

        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->workDir . '/dev.sqlite');
    }
}
