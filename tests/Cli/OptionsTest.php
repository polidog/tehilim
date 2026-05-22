<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Cli\Command\Options;

final class OptionsTest extends TestCase
{
    public function testNonSqliteUrlUnchanged(): void
    {
        self::assertSame(
            'mysql:host=localhost;dbname=app',
            Options::resolveSqliteUrl('mysql:host=localhost;dbname=app', '/proj/schema.tehilim'),
        );
    }

    public function testMemoryAndAbsoluteUnchanged(): void
    {
        self::assertSame('sqlite::memory:', Options::resolveSqliteUrl('sqlite::memory:', '/proj/schema.tehilim'));
        self::assertSame('sqlite:/abs/dev.sqlite', Options::resolveSqliteUrl('sqlite:/abs/dev.sqlite', '/proj/schema.tehilim'));
    }

    public function testRelativePathsResolveAgainstSchemaDir(): void
    {
        $dir = sys_get_temp_dir() . '/tehilim-opts-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $schema = $dir . '/schema.tehilim';
        touch($schema);

        try {
            self::assertSame("sqlite:{$dir}/dev.sqlite", Options::resolveSqliteUrl('sqlite:dev.sqlite', $schema));
            self::assertSame("sqlite:{$dir}/dev.sqlite", Options::resolveSqliteUrl('sqlite:./dev.sqlite', $schema));
            // A parent-relative path must keep its "..", not be flattened away.
            self::assertSame("sqlite:{$dir}/../dev.sqlite", Options::resolveSqliteUrl('sqlite:../dev.sqlite', $schema));
        } finally {
            unlink($schema);
            rmdir($dir);
        }
    }
}
