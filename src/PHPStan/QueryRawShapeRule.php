<?php

declare(strict_types=1);

namespace Polidog\Tehilim\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Polidog\Tehilim\Query\RawShape;

/**
 * Reports unknown type tags in a literal `queryRaw()` shape at analysis time,
 * so a typo like `'itn'` fails PHPStan instead of throwing on first execution.
 *
 * @implements Rule<MethodCall>
 */
final class QueryRawShapeRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!QueryRawShapeArgument::isQueryRawCall($node, $scope)) {
            return [];
        }
        $shape = QueryRawShapeArgument::constantShape($node, $scope);
        if ($shape === null) {
            return [];
        }
        $entries = QueryRawShapeArgument::entries($shape);
        if ($entries === null) {
            return [];
        }

        $errors = [];
        foreach ($entries as $column => $tag) {
            if (RawShape::isKnown($tag)) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(
                sprintf("Unknown tehilim raw type tag '%s' for column '%s'.", $tag, $column),
            )
                ->identifier('tehilim.queryRaw.unknownTag')
                ->tip(sprintf('Known tags: %s. Prefix with ? for nullable.', RawShape::knownTags()))
                ->build()
            ;
        }

        return $errors;
    }
}
