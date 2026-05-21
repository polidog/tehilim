<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;

final class SelectNarrowingTest extends TypeInferenceTestCase
{
    /**
     * @dataProvider dataFileAsserts
     */
    public function testFileAsserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/Fixtures/select-narrowing.php');
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../extension.neon'];
    }
}
