<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\TwigVisitor;

use Twig\Environment;
use Twig\Node\Expression\Test\TrueTest;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Since Twig 3.27, every expression used in a boolean context (if, not, and, or, xor)
 * is wrapped in a TrueTest that compiles to:
 *
 *     (($tmp = <expr>) && $tmp instanceof Markup ? (string) $tmp : $tmp)
 *
 * This only exists to coerce Markup instances to a string at runtime. For static
 * analysis it destroys the type information of <expr> and produces bogus errors
 * (instanceof.alwaysFalse, cast.useless, booleanAnd.leftAlwaysFalse). Unwrap it so
 * the compiled code contains the plain expression again, like before Twig 3.27.
 */
final readonly class UnwrapTrueTestNodeVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if ($node instanceof TrueTest) {
            return $node->getNode('node');
        }

        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
