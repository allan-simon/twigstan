<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\TwigVisitor;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * Replacement for TwigComponent's `{% props %}` node (PropsNode) that compiles
 * to code PHPStan can reason about.
 *
 * At runtime the anonymous component's context is prepared by the TwigComponent
 * runtime (props + `attributes`). Statically, the context comes from the render
 * points collected at the component's usage sites. This node bridges the two:
 *
 * - `twigstan_type_hint($context, 'attributes', ..., false);` so the variable
 *   always exists with its real type (ComponentAttributes cannot be
 *   instantiated portably: its constructor differs between versions).
 * - `$context['prop'] ??= <default>;` for a prop with a default value.
 * - `$context['prop'] = $context['prop'];` for a required prop: when a usage
 *   site does not pass the prop, PHPStan reports that the offset might not
 *   exist, which is exactly the error we want to surface.
 */
#[YieldReady]
final class AnalyzableComponentPropsNode extends Node
{
    private const string COMPONENT_ATTRIBUTES_CLASS = 'Symfony\UX\TwigComponent\ComponentAttributes';

    /**
     * @param list<string> $propsNames
     * @param array<string, Node> $values
     */
    public function __construct(array $propsNames, array $values, int $lineno)
    {
        parent::__construct($values, ['names' => $propsNames], $lineno);
    }

    /**
     * Names of the props declared with a default value.
     *
     * @return list<string>
     */
    public function getNamesWithDefault(): array
    {
        $names = [];
        foreach ($this->getAttribute('names') as $name) {
            if ($this->hasNode($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->addDebugInfo($this);

        $compiler
            ->write(sprintf('twigstan_type_hint($context, \'attributes\', \'\%s\', false);', self::COMPONENT_ATTRIBUTES_CLASS))
            ->raw("\n");

        foreach ($this->getAttribute('names') as $name) {
            if ( ! $this->hasNode($name)) {
                // Required prop: reading the offset makes PHPStan verify that
                // every usage site actually passes it.
                $compiler
                    ->write(sprintf('$context[%1$s] = $context[%1$s];', var_export($name, true)))
                    ->raw("\n");

                continue;
            }

            $compiler
                ->write(sprintf('$context[%s] ??= ', var_export($name, true)))
                ->subcompile($this->getNode($name))
                ->raw(";\n");
        }
    }
}
