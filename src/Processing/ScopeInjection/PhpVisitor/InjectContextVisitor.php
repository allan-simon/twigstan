<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection\PhpVisitor;

use LogicException;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Printer\Printer;
use TwigStan\Processing\ScopeInjection\ArrayShapeMerger;
use TwigStan\Processing\ScopeInjection\TwigScopeInjector;

/**
 * @phpstan-import-type ContextData from TwigScopeInjector
 */
final class InjectContextVisitor extends NodeVisitorAbstract
{
    /**
     * @param list<ContextData> $contextBeforeBlock
     * @param null|ArrayShapeNode $formThemeContext the FormRenderer contract, when the template is a form theme
     */
    public function __construct(
        private readonly array $contextBeforeBlock,
        private readonly ?ArrayShapeNode $formThemeContext,
        private readonly ArrayShapeMerger $arrayShapeMerger,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        // Search for the following pattern:
        //     // line 7
        //    /**
        //     * @param array{} $context
        //     * @return iterable<scalar>
        //     */
        //    public function block_main(array $context) : iterable

        if ( ! $node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $phpDoc = $node->getDocComment();

        if ($phpDoc === null) {
            return null;
        }

        if (preg_match('/^(?<parent>parent_)?block_(?<blockName>\w+)$/', $node->name->name, $match) === 1) {
            $contextBeforeBlock = $this->getContextBeforeBlock(
                $match['blockName'],
                $match['parent'] !== '',
            );

            $context = $contextBeforeBlock;
        } else {
            return null;
        }

        $shape = (new Printer())->print($context);

        $node->setDocComment(
            new Doc(
                sprintf(
                    <<<'DOC'
                        /**
                         * @param %s $context
                         * @param array{} $blocks
                         * @return iterable<null|scalar|\Stringable>
                         */
                        DOC,
                    $shape,
                ),
            ),
        );

        // The shape above holds until the first compiled `{% for %}`, which
        // writes to `$context` and makes PHPStan widen it away. Restate it where
        // the loops undo it, or everything a template renders inside a loop goes
        // unchecked.
        //
        // UNSEALED, unlike the one on the method: inside a loop the context also
        // carries `loop`, the loop variable, and whatever `{% set %}` put there.
        // A sealed shape would report every one of them as undefined.
        // UNSEALED, unlike the one on the method: inside a loop the context also
        // carries Twig's `loop` bookkeeping, the loop variable, and whatever
        // `{% set %}` put there — a sealed shape reports every one of them as
        // undefined. The rest type is spelled out, or level 6 faults the
        // docblock itself, once per loop.
        $loopShape = (new Printer())->print(ArrayShapeNode::createUnsealed(
            [...$context->items, ...$this->twigLoopBookkeeping()],
            new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('mixed'), null),
        ));

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NarrowContextInLoopsVisitor($loopShape));

        $stmts = [];

        foreach ($traverser->traverse($node->stmts ?? []) as $stmt) {
            if ( ! $stmt instanceof Node\Stmt) {
                throw new LogicException(sprintf('Narrowing the context turned a statement into %s.', $stmt::class));
            }

            $stmts[] = $stmt;
        }

        $node->stmts = $stmts;

        return $node;
    }

    /**
     * The keys Twig's compiled loop keeps in the context, which the narrowing
     * docblock must not drop.
     *
     * `loop` is set BEFORE the foreach, so restating the block shape at the top
     * of the body would hide it. It is listed unconditionally: a template that
     * never writes `{% for … %}` with `loop` simply never reads it, and a
     * phantom key costs nothing. `revindex`, `length` and `last` only exist when
     * the sequence is countable, but they are listed as required — Twig guards
     * its own reads with isset(), and marking them optional would fault every
     * template that legitimately uses them on a countable sequence.
     *
     * @return list<ArrayShapeItemNode>
     */
    private function twigLoopBookkeeping(): array
    {
        $mixed = new IdentifierTypeNode('mixed');
        $int = new IdentifierTypeNode('int');
        $bool = new IdentifierTypeNode('bool');

        $item = static fn(string $name, IdentifierTypeNode $type): ArrayShapeItemNode => new ArrayShapeItemNode(
            new IdentifierTypeNode($name),
            false,
            $type,
        );

        return [
            $item('_parent', $mixed),
            $item('_seq', $mixed),
            $item('_key', $mixed),
            new ArrayShapeItemNode(
                new IdentifierTypeNode('loop'),
                false,
                ArrayShapeNode::createSealed([
                    $item('parent', $mixed),
                    $item('index0', $int),
                    $item('index', $int),
                    $item('first', $bool),
                    $item('revindex0', $int),
                    $item('revindex', $int),
                    $item('length', $int),
                    $item('last', $bool),
                ]),
            ),
        ];
    }

    private function getContextBeforeBlock(string $blockName, bool $parent): ArrayShapeNode
    {
        $context = null;
        foreach ($this->contextBeforeBlock as $contextBeforeBlock) {
            if ($contextBeforeBlock['blockName'] !== $blockName) {
                continue;
            }

            if ($contextBeforeBlock['parent'] !== $parent) {
                continue;
            }

            if ($context === null) {
                $context = $contextBeforeBlock['context'];

                continue;
            }

            $context = $this->arrayShapeMerger->merge($context, $contextBeforeBlock['context']);
        }

        // The blocks of a form theme are called at runtime by FormRenderer with
        // the FormView variables: complete whatever was statically collected
        // (globals, `block()` call sites) with that contract.
        if ($this->formThemeContext !== null) {
            $context = $context === null
                ? $this->formThemeContext
                : $this->arrayShapeMerger->merge($context, $this->formThemeContext, true);
        }

        return $context ?? ArrayShapeNode::createSealed([]);
    }
}
