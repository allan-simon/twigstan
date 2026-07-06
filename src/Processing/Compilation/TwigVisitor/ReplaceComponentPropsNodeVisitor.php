<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\TwigVisitor;

use Symfony\UX\TwigComponent\Twig\PropsNode;
use Twig\Environment;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Replaces TwigComponent's `{% props %}` node by an analyzable equivalent.
 *
 * Only active when symfony/ux-twig-component is installed; otherwise no
 * template can contain a PropsNode and the visitor is inert.
 */
final readonly class ReplaceComponentPropsNodeVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if ( ! class_exists(PropsNode::class)) {
            return $node;
        }

        if ( ! $node instanceof PropsNode) {
            return $node;
        }

        $propsNames = $node->getAttribute('names');

        $values = [];
        foreach ($propsNames as $name) {
            if ($node->hasNode($name)) {
                $values[$name] = $node->getNode($name);
            }
        }

        return new AnalyzableComponentPropsNode($propsNames, $values, $node->getTemplateLine());
    }

    public function getPriority(): int
    {
        return 0;
    }
}
