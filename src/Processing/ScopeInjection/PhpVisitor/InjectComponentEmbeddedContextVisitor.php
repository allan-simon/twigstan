<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection\PhpVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Printer\Printer;

/**
 * Injects the context collected by ComponentEmbedContextCollector into the
 * block methods of embedded TwigComponent classes.
 *
 * A `<twig:x>...</twig:x>` usage compiles to an extra class in the host
 * template's file, suffixed with the embedded template index (`___35199393592`).
 * Its blocks are rendered with the component's variables, which are not
 * statically linked to the host template; without this visitor they keep the
 * empty context set at compilation time.
 *
 * Only the blocks the embedded template declares itself (listed in its
 * constructor's `$this->blocks = [...]`) are updated: the flattening stage
 * also inlines the host's parent blocks into the class, and those run with
 * the host context already injected by InjectContextVisitor.
 */
final class InjectComponentEmbeddedContextVisitor extends NodeVisitorAbstract
{
    private ?ArrayShapeNode $contextForCurrentClass = null;

    /**
     * @var list<string>
     */
    private array $declaredBlocksOfCurrentClass = [];

    /**
     * @param array<int, ArrayShapeNode> $contextByEmbeddedTemplateIndex
     */
    public function __construct(
        private readonly array $contextByEmbeddedTemplateIndex,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->contextForCurrentClass = null;
            $this->declaredBlocksOfCurrentClass = [];

            if ($node->name !== null && preg_match('/___(?<index>\d+)$/', $node->name->name, $match) === 1) {
                $this->contextForCurrentClass = $this->contextByEmbeddedTemplateIndex[(int) $match['index']] ?? null;
                $this->declaredBlocksOfCurrentClass = $this->getDeclaredBlocks($node);
            }

            return null;
        }

        if ($this->contextForCurrentClass === null) {
            return null;
        }

        if ( ! $node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        if (preg_match('/^block_(?<blockName>\w+)$/', $node->name->name, $match) !== 1) {
            return null;
        }

        if ( ! in_array($match['blockName'], $this->declaredBlocksOfCurrentClass, true)) {
            return null;
        }

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
                    (new Printer())->print($this->contextForCurrentClass),
                ),
            ),
        );

        return $node;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->contextForCurrentClass = null;
            $this->declaredBlocksOfCurrentClass = [];
        }

        return null;
    }

    /**
     * Reads the block names from the `$this->blocks = ['name' => ...]`
     * assignment in the embedded class constructor.
     *
     * @return list<string>
     */
    private function getDeclaredBlocks(Node\Stmt\Class_ $class): array
    {
        foreach ($class->stmts as $stmt) {
            if ( ! $stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            if ($stmt->name->name !== '__construct') {
                continue;
            }

            foreach ($stmt->stmts ?? [] as $constructorStmt) {
                if ( ! $constructorStmt instanceof Node\Stmt\Expression) {
                    continue;
                }

                if ( ! $constructorStmt->expr instanceof Node\Expr\Assign) {
                    continue;
                }

                $assign = $constructorStmt->expr;

                if ( ! $assign->var instanceof Node\Expr\PropertyFetch) {
                    continue;
                }

                if ( ! $assign->var->name instanceof Node\Identifier) {
                    continue;
                }

                if ($assign->var->name->name !== 'blocks') {
                    continue;
                }

                if ( ! $assign->expr instanceof Node\Expr\Array_) {
                    continue;
                }

                $blockNames = [];
                foreach ($assign->expr->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_) {
                        $blockNames[] = $item->key->value;
                    }
                }

                return $blockNames;
            }
        }

        return [];
    }
}
