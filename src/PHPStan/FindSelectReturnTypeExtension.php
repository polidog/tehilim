<?php

declare(strict_types=1);

namespace Polidog\Tehilim\PHPStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Polidog\Tehilim\Client\BaseModelClient;

/**
 * Narrows the return type of find* methods when the caller passes a literal
 * `select` argument. The narrowed shape contains only the row columns whose
 * select value is constant `true`, plus the primary key (matching the
 * runtime's auto-include behavior).
 *
 *   $db->user->findUnique([
 *       'where'  => ['id' => 1],
 *       'select' => ['email' => true, 'name' => true],
 *   ]);
 *   // before:  array{id:int, email:string, name:?string, ...}|null
 *   // after:   array{email:string, name:?string, id:int}|null
 *
 * When `select` is absent, dynamic, or has no `true` entries, the default
 * return type is preserved.
 */
final class FindSelectReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return BaseModelClient::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array(
            $methodReflection->getName(),
            ['findMany', 'findFirst', 'findUnique'],
            true,
        );
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

        $args = $methodCall->getArgs();
        if ($args === []) {
            return $defaultReturn;
        }

        $argsType = $scope->getType($args[0]->value);
        $argsConst = $this->singleConstantArray($argsType);
        if ($argsConst === null) {
            return $defaultReturn;
        }

        $selectType = $argsConst->getOffsetValueType(new ConstantStringType('select'));
        $selectConst = $this->singleConstantArray($selectType);
        if ($selectConst === null) {
            return $defaultReturn;
        }

        $pickedKeys = $this->extractPickedKeys($selectConst);
        if ($pickedKeys === []) {
            return $defaultReturn;
        }

        $pk = $this->resolvePrimaryKey($methodCall, $scope);
        if ($pk !== null && !in_array($pk, $pickedKeys, true)) {
            $pickedKeys[] = $pk;
        }

        $unwrapped = $this->unwrapRow($defaultReturn);
        if ($unwrapped === null) {
            return $defaultReturn;
        }
        [$row, $wrap] = $unwrapped;

        $narrowed = $this->narrow($row, $pickedKeys);
        if ($narrowed === null) {
            return $defaultReturn;
        }

        return $this->wrap($narrowed, $wrap);
    }

    private function singleConstantArray(Type $type): ?ConstantArrayType
    {
        $arrays = $type->getConstantArrays();

        return count($arrays) === 1 ? $arrays[0] : null;
    }

    /**
     * Picks column names from a select argument that can be either:
     *   - list form:  ['id', 'email']
     *   - map form:   ['id' => true, 'email' => true]
     *
     * @return list<string>
     */
    private function extractPickedKeys(ConstantArrayType $select): array
    {
        $picked = [];
        $isList = $select->isList()->yes();
        $valueTypes = $select->getValueTypes();
        foreach ($select->getKeyTypes() as $i => $keyType) {
            $valueType = $valueTypes[$i] ?? null;
            if ($valueType === null) {
                continue;
            }
            if ($isList) {
                $strings = $valueType->getConstantStrings();
                if (count($strings) === 1) {
                    $picked[] = $strings[0]->getValue();
                }

                continue;
            }
            $strings = $keyType->getConstantStrings();
            if (count($strings) !== 1 || !$valueType->isTrue()->yes()) {
                continue;
            }
            $picked[] = $strings[0]->getValue();
        }

        return $picked;
    }

    private function resolvePrimaryKey(MethodCall $methodCall, Scope $scope): ?string
    {
        $callerType = $scope->getType($methodCall->var);
        $classReflections = $callerType->getObjectClassReflections();
        if ($classReflections === []) {
            return null;
        }
        $native = $classReflections[0]->getNativeReflection();
        if (!$native->hasConstant('PK')) {
            return null;
        }
        $pk = $native->getConstant('PK');

        return is_string($pk) ? $pk : null;
    }

    /**
     * @return null|array{0: ConstantArrayType, 1: string}
     */
    private function unwrapRow(Type $defaultReturn): ?array
    {
        if ($defaultReturn->isList()->yes()) {
            $inner = $defaultReturn->getIterableValueType();
            $row = $this->singleConstantArray($inner);
            if ($row !== null) {
                return [$row, 'list'];
            }

            return null;
        }
        $nonNull = TypeCombinator::removeNull($defaultReturn);
        $row = $this->singleConstantArray($nonNull);
        if ($row !== null) {
            return [$row, $nonNull === $defaultReturn ? 'plain' : 'nullable'];
        }

        return null;
    }

    /**
     * Build a new array shape containing only the selected keys (and PK),
     * skipping any keys not present in the original row shape and any
     * optional keys (which represent relations, not scalar columns).
     *
     * @param list<string> $keys
     */
    private function narrow(ConstantArrayType $row, array $keys): ?ConstantArrayType
    {
        $optionalIndices = array_flip($row->getOptionalKeys());
        $scalarKeys = [];
        foreach ($row->getKeyTypes() as $i => $kt) {
            if (isset($optionalIndices[$i])) {
                continue;
            }
            $strings = $kt->getConstantStrings();
            if (count($strings) === 1) {
                $scalarKeys[$strings[0]->getValue()] = true;
            }
        }

        $newKeys = [];
        $newValues = [];
        foreach ($keys as $key) {
            if (!isset($scalarKeys[$key])) {
                continue;
            }
            $keyType = new ConstantStringType($key);
            if (!$row->hasOffsetValueType($keyType)->yes()) {
                continue;
            }
            $newKeys[] = $keyType;
            $newValues[] = $row->getOffsetValueType($keyType);
        }
        if ($newKeys === []) {
            return null;
        }

        return new ConstantArrayType($newKeys, $newValues);
    }

    private function wrap(ConstantArrayType $narrowed, string $wrap): Type
    {
        return match ($wrap) {
            'list' => TypeCombinator::intersect(
                new ArrayType(new IntegerType(), $narrowed),
                new AccessoryArrayListType(),
            ),
            'nullable' => TypeCombinator::union($narrowed, new NullType()),
            default => $narrowed,
        };
    }
}
