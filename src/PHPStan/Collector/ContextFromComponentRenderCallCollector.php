<?php

declare(strict_types=1);

namespace TwigStan\PHPStan\Collector;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Type\Constant\ConstantArrayType;
use TwigStan\Twig\AnonymousComponentTemplateResolver;
use TwigStan\Twig\CommentHelper;

/**
 * Collects the context of anonymous TwigComponents from their usage sites.
 *
 * The props passed to a component become the context of its template, both for
 * the self-closing syntax and for the embedded syntax:
 *
 *     yield $this->env->getRuntime('...\ComponentRuntime')->render("Alert", ["message" => "..."]);
 *     $preRenderEvent = $v0->startEmbedComponent("Alert", CoreExtension::toArray(["message" => "..."]), $context, "home.twig", 35199393592);
 *
 * The `attributes` variable and prop defaults are not part of the collected
 * context; they are compiled into the component template itself by
 * AnalyzableComponentPropsNode.
 *
 * @implements TemplateContextCollector<Node\Stmt\Expression>
 */
final readonly class ContextFromComponentRenderCallCollector implements TemplateContextCollector
{
    private const string COMPONENT_RUNTIME_CLASS = 'Symfony\UX\TwigComponent\Twig\ComponentRuntime';

    public function __construct(
        private AnonymousComponentTemplateResolver $templateResolver,
    ) {}

    public function getNodeType(): string
    {
        return Node\Stmt\Expression::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $result = [];

        foreach ((new NodeFinder())->findInstanceOf([$node], Node\Expr\MethodCall::class) as $methodCall) {
            if ( ! $methodCall->name instanceof Node\Identifier) {
                continue;
            }

            if ( ! in_array($methodCall->name->name, ['render', 'startEmbedComponent'], true)) {
                continue;
            }

            if ( ! in_array(self::COMPONENT_RUNTIME_CLASS, $scope->getType($methodCall->var)->getObjectClassNames(), true)) {
                continue;
            }

            $args = $methodCall->getArgs();

            if ( ! isset($args[0])) {
                continue;
            }

            $componentNames = $scope->getType($args[0]->value)->getConstantStrings();

            if (count($componentNames) === 0) {
                continue;
            }

            $props = isset($args[1])
                ? TwigComponentCallHelper::mergeShapes($scope->getType(TwigComponentCallHelper::unwrapToArrayCall($args[1]->value))->getConstantArrays())
                : new ConstantArrayType([], []);

            if ($props === null) {
                continue;
            }

            $context = (new Printer())->print($props->toPhpDocNode());

            $sourceLocation = CommentHelper::getSourceLocationFromComments($node->getComments());

            if ($sourceLocation === null) {
                throw new LogicException(sprintf('Could not find Twig line number on %s:%d.', $scope->getFile(), $node->getStartLine()));
            }

            foreach ($componentNames as $componentName) {
                $template = $this->templateResolver->resolve($componentName->getValue());

                if ($template === null) {
                    continue;
                }

                $result[] = [
                    'sourceLocation' => $sourceLocation,
                    'template' => $template,
                    'context' => $context,
                ];
            }
        }

        return $result === [] ? null : $result;
    }
}
