<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\PhpVisitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Since Twig 3.27, PrintNode compiles `{{ expr }}` to `yield (string) expr;` unless
 * the expression is known to be a string. The cast is a runtime coercion detail that
 * destroys the expression for static analysis: it produces cast.useless noise, hides
 * the printed expression from collectors (e.g. the include collector matching
 * `yield CoreExtension::include(...)`), and the template author cannot act on any
 * error reported about it. Remove it so the compiled code yields the plain
 * expression again, like before Twig 3.27.
 */
final class RemoveStringCastFromYieldVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node\Expr\Yield_
    {
        if ( ! $node instanceof Node\Expr\Yield_) {
            return null;
        }

        if ( ! $node->value instanceof Node\Expr\Cast\String_) {
            return null;
        }

        $node->value = $node->value->expr;

        return $node;
    }
}
