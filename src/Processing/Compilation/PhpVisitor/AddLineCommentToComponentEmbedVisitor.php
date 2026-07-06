<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\PhpVisitor;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * TwigComponent's ComponentNode compiles a `<twig:x>...</twig:x>` usage into a
 * group of statements of which only the first carries the `// line` comment:
 *
 *     // line homepage.twig:2
 *     $v0 = $this->env->getRuntime(ComponentRuntime::class);
 *     $preRendered = $v0->preRender("Alert", ...);
 *     if (null !== $preRendered) { ... } else {
 *         $preRenderEvent = $v0->startEmbedComponent("Alert", ...);
 *         ...
 *     }
 *
 * The collectors locate the Twig source through that comment, so this visitor
 * copies the last seen `// line` comment onto the `startEmbedComponent` call.
 */
final class AddLineCommentToComponentEmbedVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<Comment>
     */
    private array $lastLineComments = [];

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->lastLineComments = [];

            return null;
        }

        if ( ! $node instanceof Node\Stmt) {
            return null;
        }

        foreach ($node->getComments() as $comment) {
            if (str_starts_with($comment->getText(), '// line ')) {
                $this->lastLineComments = $node->getComments();

                return null;
            }
        }

        if ($this->lastLineComments === []) {
            return null;
        }

        if ( ! $node instanceof Node\Stmt\Expression) {
            return null;
        }

        if ( ! $node->expr instanceof Node\Expr\Assign) {
            return null;
        }

        if ( ! $node->expr->expr instanceof Node\Expr\MethodCall) {
            return null;
        }

        if ( ! $node->expr->expr->name instanceof Node\Identifier) {
            return null;
        }

        if ($node->expr->expr->name->name !== 'startEmbedComponent') {
            return null;
        }

        $node->setAttribute('comments', $this->lastLineComments);

        return $node;
    }
}
