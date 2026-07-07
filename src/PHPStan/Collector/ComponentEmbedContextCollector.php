<?php

declare(strict_types=1);

namespace TwigStan\PHPStan\Collector;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use TwigStan\Twig\CommentHelper;
use TwigStan\Twig\SourceLocation;

/**
 * Collects the context available inside the embedded part of a TwigComponent
 * (the body written between `<twig:x>` and `</twig:x>`).
 *
 * The compiled host template contains:
 *
 *     $preRenderEvent = $v0->startEmbedComponent("Alert", CoreExtension::toArray([...props...]), $context, "home.twig", 35199393592);
 *
 * At runtime the embedded template's blocks are rendered with the component's
 * variables: the host context, overridden by the props, plus `attributes`.
 * The collected context is injected into the `block_*` methods of the
 * embedded class (suffixed `___35199393592`) by InjectComponentEmbeddedContextVisitor.
 *
 * The collector runs in two phases. During the block-contexts collect phase
 * (the bootstrap capture) the block contexts are not injected yet, so when the
 * usage site sits inside a block, the host part of the context is not
 * resolvable; `relatedBlockName` records the enclosing block so that
 * TwigScopeInjector can merge its context in, exactly like for regular blocks.
 * When components nest (`<twig:x>` inside the body of another `<twig:y>`),
 * the usage site sits inside a block of the enclosing embedded class;
 * `relatedEmbeddedTemplateIndex` records that class so the enclosing embedded
 * context can be merged in instead.
 *
 * During the analysis phase the collector runs again, this time with the real
 * block `@param` injected: the capture at the usage site is complete — it even
 * includes `{% set %}` variables set before the component, which the merged
 * block context cannot see. AnalyzeCommand compares this capture with what the
 * run injected and, when it differs, feeds it back into the next run, where it
 * replaces the bootstrap capture (the related* fields are then moot).
 *
 * @phpstan-type ComponentEmbedContextData = array{
 *     embeddedTemplateIndex: int,
 *     sourceLocation: SourceLocation,
 *     context: string,
 *     relatedBlockName: null|string,
 *     relatedParent: bool,
 *     relatedEmbeddedTemplateIndex: null|int,
 * }
 * @implements Collector<Node\Stmt\Expression, ComponentEmbedContextData>
 */
final readonly class ComponentEmbedContextCollector implements Collector, ExportingCollector
{
    private const string COMPONENT_RUNTIME_CLASS = 'Symfony\UX\TwigComponent\Twig\ComponentRuntime';
    private const string COMPONENT_ATTRIBUTES_CLASS = 'Symfony\UX\TwigComponent\ComponentAttributes';

    public function getNodeType(): string
    {
        return Node\Stmt\Expression::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ( ! $node->expr instanceof Node\Expr\Assign) {
            return null;
        }

        $methodCall = $node->expr->expr;

        if ( ! $methodCall instanceof Node\Expr\MethodCall) {
            return null;
        }

        if ( ! $methodCall->name instanceof Node\Identifier) {
            return null;
        }

        if ($methodCall->name->name !== 'startEmbedComponent') {
            return null;
        }

        if ( ! in_array(self::COMPONENT_RUNTIME_CLASS, $scope->getType($methodCall->var)->getObjectClassNames(), true)) {
            return null;
        }

        $args = $methodCall->getArgs();

        if (count($args) < 5) {
            return null;
        }

        $embeddedTemplateIndexes = $scope->getType($args[4]->value)->getConstantScalarValues();

        if (count($embeddedTemplateIndexes) !== 1 || ! is_int($embeddedTemplateIndexes[0])) {
            return null;
        }

        $hostContext = TwigComponentCallHelper::mergeShapes($scope->getType($args[2]->value)->getConstantArrays());

        $builder = $hostContext !== null
            ? ConstantArrayTypeBuilder::createFromConstantArray($hostContext)
            : ConstantArrayTypeBuilder::createEmpty();

        $props = TwigComponentCallHelper::mergeShapes(
            $scope->getType(TwigComponentCallHelper::unwrapToArrayCall($args[1]->value))->getConstantArrays(),
        );

        if ($props !== null) {
            foreach ($props->getKeyTypes() as $key) {
                $builder->setOffsetValueType($key, $props->getOffsetValueType($key), $props->hasOffsetValueType($key)->maybe());
            }
        }

        $builder->setOffsetValueType(
            new ConstantStringType('attributes'),
            new ObjectType(self::COMPONENT_ATTRIBUTES_CLASS),
        );

        $sourceLocation = CommentHelper::getSourceLocationFromComments($node->getComments());

        if ($sourceLocation === null) {
            throw new LogicException(sprintf('Could not find Twig line number on %s:%d.', $scope->getFile(), $node->getStartLine()));
        }

        $functionName = $scope->getFunctionName();

        if (null !== $functionName) {
            preg_match('/^(?<parent>parent_)?block_(?<blockName>\w+)$/', $functionName, $match);
        }

        $relatedEmbeddedTemplateIndex = null;
        $className = $scope->getClassReflection()?->getName();

        if ($className !== null && preg_match('/___(?<index>\d+)$/', $className, $classMatch) === 1) {
            $relatedEmbeddedTemplateIndex = (int) $classMatch['index'];
        }

        return [
            'embeddedTemplateIndex' => $embeddedTemplateIndexes[0],
            'sourceLocation' => $sourceLocation,
            'context' => (new Printer())->print($builder->getArray()->toPhpDocNode()),
            'relatedBlockName' => $match['blockName'] ?? null,
            'relatedParent' => ($match['parent'] ?? '') !== '',
            'relatedEmbeddedTemplateIndex' => $relatedEmbeddedTemplateIndex,
        ];
    }
}
