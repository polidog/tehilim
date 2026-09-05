<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Polidog\Tehilim\PHPStan\QueryRawShapeRule;
use Polidog\Tehilim\Query\RawShape;

/**
 * @extends RuleTestCase<QueryRawShapeRule>
 */
final class QueryRawShapeRuleTest extends RuleTestCase
{
    public function testReportsUnknownTagsInLiteralShapes(): void
    {
        $tip = sprintf('Known tags: %s. Prefix with ? for nullable.', RawShape::knownTags());

        $this->analyse([__DIR__ . '/Fixtures/query-raw-shape-rule.php'], [
            ["Unknown tehilim raw type tag 'itn' for column 'id'.", 11, $tip],
            ["Unknown tehilim raw type tag '?integer' for column 'id'.", 12, $tip],
            ["Unknown tehilim raw type tag 'nope' for column 'x'.", 13, $tip],
            ["Unknown tehilim raw type tag 'Nope' for column 'y'.", 13, $tip],
        ]);
    }

    protected function getRule(): Rule
    {
        return new QueryRawShapeRule();
    }
}
