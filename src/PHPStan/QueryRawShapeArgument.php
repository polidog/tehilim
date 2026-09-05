<?php

declare(strict_types=1);

namespace Polidog\Tehilim\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ObjectType;
use Polidog\Tehilim\Client\BaseClient;

/**
 * Shared plumbing for the `queryRaw()` extension and rule: recognizes a
 * `BaseClient::queryRaw()` call and locates its `$shape` argument, whether
 * passed positionally (index 2) or by name.
 */
final class QueryRawShapeArgument
{
    public const int POSITION = 2;
    public const string NAME = 'shape';

    public static function isQueryRawCall(MethodCall $call, Scope $scope): bool
    {
        if (!$call->name instanceof Identifier || $call->name->toString() !== 'queryRaw') {
            return false;
        }

        return (new ObjectType(BaseClient::class))
            ->isSuperTypeOf($scope->getType($call->var))
            ->yes()
        ;
    }

    public static function find(MethodCall $call): ?Arg
    {
        $args = $call->getArgs();
        foreach ($args as $arg) {
            if ($arg->name instanceof Identifier && $arg->name->toString() === self::NAME) {
                return $arg;
            }
        }
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return null;
            }
        }

        return $args[self::POSITION] ?? null;
    }

    /**
     * Resolve the shape argument to a single constant array with at least one
     * entry. Anything else (absent, dynamic, union of shapes, empty) means the
     * caller did not declare a literal shape and gets the default typing.
     */
    public static function constantShape(MethodCall $call, Scope $scope): ?ConstantArrayType
    {
        $arg = self::find($call);
        if ($arg === null) {
            return null;
        }
        $arrays = $scope->getType($arg->value)->getConstantArrays();
        if (count($arrays) !== 1) {
            return null;
        }
        $shape = $arrays[0];
        if ($shape->isIterableAtLeastOnce()->no()) {
            return null;
        }

        return $shape;
    }

    /**
     * Column => tag pairs from a constant shape. Returns null if any key or
     * value is not a single constant string, since a partially known shape
     * cannot be typed honestly.
     *
     * @return null|array<string,string>
     */
    public static function entries(ConstantArrayType $shape): ?array
    {
        $entries = [];
        $valueTypes = $shape->getValueTypes();
        foreach ($shape->getKeyTypes() as $i => $keyType) {
            $keys = $keyType->getConstantStrings();
            $values = ($valueTypes[$i] ?? null)?->getConstantStrings() ?? [];
            if (count($keys) !== 1 || count($values) !== 1) {
                return null;
            }
            $entries[$keys[0]->getValue()] = $values[0]->getValue();
        }

        return $entries === [] ? null : $entries;
    }
}
