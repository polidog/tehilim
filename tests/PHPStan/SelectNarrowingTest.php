<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;

final class SelectNarrowingTest extends TypeInferenceTestCase
{
    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/Fixtures/select-narrowing.php');
    }

    /**
     * @dataProvider dataFileAsserts
     */
    public function testFileAsserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../extension.neon'];
    }
}
