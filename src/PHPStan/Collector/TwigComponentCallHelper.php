<?php

declare(strict_types=1);

namespace TwigStan\PHPStan\Collector;

use PhpParser\Node;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\TypeCombinator;
use Twig\Extension\CoreExtension;

/**
 * Shared logic for the collectors that inspect compiled TwigComponent calls.
 */
final readonly class TwigComponentCallHelper
{
    /**
     * The embedded syntax wraps the props in `CoreExtension::toArray(...)`,
     * which widens the type; use the wrapped expression directly.
     */
    public static function unwrapToArrayCall(Node\Expr $expr): Node\Expr
    {
        if ( ! $expr instanceof Node\Expr\StaticCall) {
            return $expr;
        }

        if ( ! $expr->class instanceof Node\Name\FullyQualified) {
            return $expr;
        }

        if ($expr->class->toString() !== CoreExtension::class) {
            return $expr;
        }

        if ( ! $expr->name instanceof Node\Identifier) {
            return $expr;
        }

        if ($expr->name->name !== 'toArray') {
            return $expr;
        }

        $args = $expr->getArgs();

        if ( ! isset($args[0])) {
            return $expr;
        }

        return $args[0]->value;
    }

    /**
     * Merges a union of constant array shapes into a single shape: keys
     * missing from (or optional in) one member become optional, value types
     * are unioned. Returns null when the list is empty.
     *
     * @param list<ConstantArrayType> $shapes
     */
    public static function mergeShapes(array $shapes): ?ConstantArrayType
    {
        if ($shapes === []) {
            return null;
        }

        if (count($shapes) === 1) {
            return $shapes[0];
        }

        $keyTypes = [];
        foreach ($shapes as $shape) {
            foreach ($shape->getKeyTypes() as $keyType) {
                $keyTypes[(string) $keyType->getValue()] ??= $keyType;
            }
        }

        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($keyTypes as $keyType) {
            $valueTypes = [];
            $optional = false;

            foreach ($shapes as $shape) {
                $hasOffset = $shape->hasOffsetValueType($keyType);

                if ($hasOffset->no()) {
                    $optional = true;

                    continue;
                }

                if ($hasOffset->maybe()) {
                    $optional = true;
                }

                $valueTypes[] = $shape->getOffsetValueType($keyType);
            }

            $builder->setOffsetValueType($keyType, TypeCombinator::union(...$valueTypes), $optional);
        }

        $merged = $builder->getArray();

        return $merged->getConstantArrays()[0] ?? null;
    }
}
