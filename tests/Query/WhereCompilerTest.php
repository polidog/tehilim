<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Query;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\SqliteDriver;
use Polidog\Tehilim\Query\WhereCompiler;

final class WhereCompilerTest extends TestCase
{
    public function testJsonPathRejectsNonScalarSegments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("JSON 'path' segments must be string or int");

        $this->compiler()->compile(
            ['profile' => ['path' => [['nested']], 'equals' => 'x']],
            $this->driver(),
            ['profile' => 'json'],
        );
    }

    public function testJsonPathRequiresListPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("JSON 'path' must be a list of keys");

        $this->compiler()->compile(
            ['profile' => ['path' => ['a' => 'b'], 'equals' => 'x']],
            $this->driver(),
            ['profile' => 'json'],
        );
    }

    public function testJsonPathRejectedOnNonJsonColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("JSON 'path' filter is only valid on Json columns ('title' is string)");

        $this->compiler()->compile(
            ['title' => ['path' => ['a'], 'equals' => 'x']],
            $this->driver(),
            ['title' => 'string'],
        );
    }

    public function testJsonPathAcceptsIntSegments(): void
    {
        [$sql, $params] = $this->compiler()->compile(
            ['profile' => ['path' => ['items', 0], 'equals' => 'x']],
            $this->driver(),
            ['profile' => 'json'],
        );

        self::assertStringContainsString('$."items"."0"', $sql);
        self::assertSame(['x'], $params);
    }

    private function compiler(): WhereCompiler
    {
        return new WhereCompiler();
    }

    private function driver(): SqliteDriver
    {
        return new SqliteDriver(new PDO('sqlite::memory:'));
    }
}
