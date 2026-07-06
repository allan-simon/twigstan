<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\PhpVisitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * TwigComponent's ComponentNode compiles the embedded template index of a
 * `<twig:x>` / `{% component %}` tag as a string:
 *
 *     $this->load("template.html.twig", 12, "35199393592")
 *
 * At runtime PHP coerces it, but `Twig\Template::load()` declares `?int $index`,
 * so PHPStan reports an `argument.type` error on every component usage.
 * This visitor rewrites the numeric string into an integer literal.
 */
final class CastComponentEmbeddedTemplateIndexVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        if ( ! $node instanceof Node\Expr\MethodCall) {
            return null;
        }

        if ( ! $node->name instanceof Node\Identifier) {
            return null;
        }

        if ($node->name->name !== 'load') {
            return null;
        }

        $args = $node->getArgs();

        if ( ! isset($args[2])) {
            return null;
        }

        if ( ! $args[2]->value instanceof Node\Scalar\String_) {
            return null;
        }

        if (preg_match('/^\d+$/', $args[2]->value->value) !== 1) {
            return null;
        }

        $args[2]->value = new Node\Scalar\Int_((int) $args[2]->value->value);

        return $node;
    }
}
