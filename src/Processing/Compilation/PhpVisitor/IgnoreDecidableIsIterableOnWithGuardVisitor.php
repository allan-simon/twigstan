<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\PhpVisitor;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Twig\Error\RuntimeError;

/**
 * Twig's `{% with %}` tag compiles a runtime guard:
 *
 *     if (!is_iterable($_v1)) {
 *         throw new RuntimeError('Variables passed to the "with" tag must be a mapping.', ...);
 *     }
 *
 * When the `with` expression is a literal mapping (`{% with { attr: row_attr } %}`),
 * the guard is statically decidable and PHPStan flags the always-true check.
 * That is generated bookkeeping, not a template bug — contrary to a user-written
 * `x is iterable` test, which stays meaningful and is left untouched.
 */
final class IgnoreDecidableIsIterableOnWithGuardVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        if ( ! $node instanceof Node\Stmt\If_) {
            return null;
        }

        if ( ! $node->cond instanceof Node\Expr\BooleanNot) {
            return null;
        }

        $negated = $node->cond->expr;

        if ( ! $negated instanceof Node\Expr\FuncCall
            || ! $negated->name instanceof Node\Name
            || $negated->name->toLowerString() !== 'is_iterable'
        ) {
            return null;
        }

        if (count($node->stmts) !== 1 || ! $node->stmts[0] instanceof Node\Stmt\Expression) {
            return null;
        }

        $thrown = $node->stmts[0]->expr;

        if ( ! $thrown instanceof Node\Expr\Throw_) {
            return null;
        }

        if ( ! $thrown->expr instanceof Node\Expr\New_
            || ! $thrown->expr->class instanceof Node\Name
            || $thrown->expr->class->toString() !== RuntimeError::class
        ) {
            return null;
        }

        $node->setAttribute('comments', [
            ...$node->getAttribute('comments', []),
            new Comment('// @phpstan-ignore function.alreadyNarrowedType, function.impossibleType'),
        ]);

        return $node;
    }
}
