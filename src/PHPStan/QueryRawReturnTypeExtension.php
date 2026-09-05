<?php

declare(strict_types=1);

namespace Polidog\Tehilim\PHPStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Polidog\Tehilim\Client\BaseClient;
use Polidog\Tehilim\Query\RawShape;

/**
 * Types `BaseClient::queryRaw()` from its literal `$shape` argument:
 *
 *   $db->queryRaw('SELECT id, COUNT(*) AS n ...', [], ['id' => 'int', 'n' => '?int']);
 *   // before:  list<array<string, mixed>>
 *   // after:   list<array{id: int, n: int|null}>
 *
 * The tag → PHP type mapping is {@see RawShape::TYPES}, the same table the
 * runtime casts with, so the static shape and the fetched rows agree by
 * construction. A shape that is absent, dynamic, or contains an unknown tag
 * leaves the default return type alone ({@see QueryRawShapeRule} reports the
 * unknown tag).
 */
final class QueryRawReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private readonly TypeStringResolver $typeStringResolver) {}

    public function getClass(): string
    {
        return BaseClient::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'queryRaw';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): Type {
        $defaultReturn = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            $methodCall->getArgs(),
            $methodReflection->getVariants(),
        )->getReturnType();

        $shape = QueryRawShapeArgument::constantShape($methodCall, $scope);
        if ($shape === null) {
            return $defaultReturn;
        }
        $entries = QueryRawShapeArgument::entries($shape);
        if ($entries === null) {
            return $defaultReturn;
        }

        $keys = [];
        $values = [];
        foreach ($entries as $column => $tag) {
            if (!RawShape::isKnown($tag)) {
                return $defaultReturn;
            }
            $keys[] = new ConstantStringType($column);
            $values[] = $this->typeStringResolver->resolve(RawShape::phpDocType($tag));
        }

        return TypeCombinator::intersect(
            new ArrayType(new IntegerType(), new ConstantArrayType($keys, $values)),
            new AccessoryArrayListType(),
        );
    }
}
