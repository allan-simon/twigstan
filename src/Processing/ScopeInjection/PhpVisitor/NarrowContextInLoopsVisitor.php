<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection\PhpVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Keeps the context array shape alive across Twig's compiled `{% for %}` loops.
 *
 * Twig compiles a loop to a `foreach` whose key and value targets are offsets of
 * the context array itself:
 *
 *     $context['_parent'] = $context;
 *     $context['_seq'] = CoreExtension::ensureTraversable($context['jours']);
 *     foreach ($context['_seq'] as $context['_key'] => $context['jour']) { … }
 *     $_parent = $context['_parent'];
 *     unset($context['_seq'], $context['_key'], $context['jour'], $context['_parent']);
 *     $context = array_intersect_key($context, $_parent) + $_parent;
 *
 * PHPStan widens a variable that a loop writes to. Because the loop writes to
 * `$context` in its own header, and again through the restore below it, the
 * precise array shape injected on the block method degrades to `mixed` values —
 * from the first loop onward, and for the rest of the method. Everything a
 * template renders inside a loop, which is most of what a list screen renders,
 * then passes analysis unchecked: an undefined property on the loop variable
 * raises nothing.
 *
 * Two changes restore it, both semantics-preserving:
 *
 *  1. the loop targets become plain locals, assigned into the context on the
 *     first lines of the body — the value keeps the element type of the
 *     sequence, and the header stops writing to `$context`;
 *  2. a `@var` docblock re-states the shape at the top of the outermost loop
 *     bodies and after the restore that follows them, undoing the widening
 *     PHPStan still performs.
 *
 * The docblock goes on the OUTERMOST loops only. It states the shape the block
 * was entered with, so restating it deeper would drop whatever `{% set %}` put
 * in the context in between — and a variable set in an enclosing loop would be
 * reported undefined. Nested loops need no docblock: they inherit a shape their
 * own header no longer widens.
 */
final class NarrowContextInLoopsVisitor extends NodeVisitorAbstract
{
    private int $compteur = 0;

    private int $profondeur = 0;

    /**
     * @param string $contextShape the printed array shape, unsealed
     */
    public function __construct(
        private readonly string $contextShape,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Foreach_) {
            return $this->rewriteLoop($node);
        }

        return null;
    }

    /**
     * @return null|list<Node>
     */
    public function leaveNode(Node $node): ?array
    {
        if ($node instanceof Node\Stmt\Foreach_) {
            if ($node->getAttribute('twigstanContextLoop') === true) {
                $this->profondeur--;
            }

            return null;
        }

        // The restore below a loop re-assigns the whole context: state the shape
        // again right after it, or the rest of the method stays widened.
        if ($this->profondeur === 0 && $this->isContextRestore($node)) {
            return [$node, $this->shapeDoc()];
        }

        return null;
    }

    private function rewriteLoop(Node\Stmt\Foreach_ $node): ?Node
    {
        $valueOffset = $this->contextOffset($node->valueVar);

        if ($valueOffset === null) {
            return null;
        }

        $this->compteur++;
        $prelude = $this->profondeur === 0 ? [$this->shapeDoc()] : [];

        if ($node->keyVar !== null && $this->contextOffset($node->keyVar) !== null) {
            $key = new Node\Expr\Variable(sprintf('__twigstanKey%d', $this->compteur));
            $prelude[] = new Node\Stmt\Expression(new Node\Expr\Assign($node->keyVar, $key));
            $node->keyVar = $key;
        }

        $value = new Node\Expr\Variable(sprintf('__twigstanValue%d', $this->compteur));
        $prelude[] = new Node\Stmt\Expression(new Node\Expr\Assign($node->valueVar, $value));
        $node->valueVar = $value;

        $node->stmts = [...$prelude, ...$node->stmts];
        $node->setAttribute('twigstanContextLoop', true);
        $this->profondeur++;

        return $node;
    }

    /**
     * The offset name when the expression is `$context['someKey']`, null otherwise.
     */
    private function contextOffset(Node\Expr $expr): ?string
    {
        if ( ! $expr instanceof Node\Expr\ArrayDimFetch) {
            return null;
        }

        if ( ! $expr->var instanceof Node\Expr\Variable || $expr->var->name !== 'context') {
            return null;
        }

        if ( ! $expr->dim instanceof Node\Scalar\String_) {
            return null;
        }

        return $expr->dim->value;
    }

    /**
     * Is this `$context = array_intersect_key($context, $_parent) + $_parent;`?
     */
    private function isContextRestore(Node $node): bool
    {
        if ( ! $node instanceof Node\Stmt\Expression) {
            return false;
        }

        if ( ! $node->expr instanceof Node\Expr\Assign) {
            return false;
        }

        $target = $node->expr->var;

        if ( ! $target instanceof Node\Expr\Variable || $target->name !== 'context') {
            return false;
        }

        $value = $node->expr->expr;

        if ( ! $value instanceof Node\Expr\BinaryOp\Plus) {
            return false;
        }

        $left = $value->left;

        return $left instanceof Node\Expr\FuncCall
            && $left->name instanceof Node\Name
            && $left->name->toString() === 'array_intersect_key';
    }

    /**
     * A statement that carries nothing but the shape docblock. `Nop` prints as
     * its comments alone, which is exactly what a bare `@var` needs.
     */
    private function shapeDoc(): Node\Stmt\Nop
    {
        $nop = new Node\Stmt\Nop();
        $nop->setDocComment(new Doc(sprintf('/** @var %s $context */', $this->contextShape)));

        return $nop;
    }
}
