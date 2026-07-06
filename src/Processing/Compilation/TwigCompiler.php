<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;
use Twig\Node\ModuleNode;
use TwigStan\PHP\PrettyPrinter;
use TwigStan\PHP\StrictPhpParser;
use TwigStan\Processing\Compilation\Parser\TwigNodeParser;
use TwigStan\Processing\Compilation\PhpVisitor\AddExtraLineNumberCommentVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\AddGetExtensionMethodVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\AddLineCommentToComponentEmbedVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\AddTypeCommentsToTemplateVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\AppendFilePathToLineCommentVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\CastComponentEmbeddedTemplateIndexVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\IgnoreArgumentTemplateTypeOnEnsureTraversableVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\IgnoreDecidableIsIterableOnWithGuardVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\MakeFinalVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\RefactorExtensionCallVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\RefactorLoopClosureVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\RemoveImportsVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\RemoveLineNumberFromGetAttributeCallVisitor;
use TwigStan\Processing\Compilation\PhpVisitor\RemoveStringCastFromYieldVisitor;
use TwigStan\Processing\Compilation\TwigVisitor\AnalyzableComponentPropsNode;
use TwigStan\Processing\TemplateContext;
use TwigStan\Processing\TemplateContextToArrayShape;
use TwigStan\Twig\Node\NodeFinder;

final readonly class TwigCompiler
{
    public function __construct(
        private TwigNodeParser $twigNodeParser,
        private PrettyPrinter $prettyPrinter,
        private Filesystem $filesystem,
        private ModifiedCompiler $compiler,
        private StrictPhpParser $phpParser,
        private TemplateContextToArrayShape $templateContextToArrayShape,
        private NodeFinder $nodeFinder,
    ) {}

    public function compile(ModuleNode | string $template, string $targetDirectory, TemplateContext $templateContext, int $run): CompilationResult
    {
        $targetDirectory = Path::join($targetDirectory, (string) $run);

        $this->filesystem->mkdir($targetDirectory);

        $twigNode = $this->twigNodeParser->parse($template);

        if ($twigNode->getSourceContext() === null) {
            throw new RuntimeException('Template does not have a source context.');
        }

        $twigFilePath = Path::canonicalize($twigNode->getSourceContext()->getPath());

        $phpSource = $this->compiler->compile($twigNode)->getSource();

        $this->filesystem->dumpFile(
            Path::join($targetDirectory, sprintf(
                '%s.original.%s.php',
                basename($twigFilePath),
                hash('crc32', $twigFilePath),
            )),
            $phpSource,
        );

        $stmts = $this->phpParser->parse($phpSource);

        $stmts = $this->applyVisitors(
            $stmts,
            new NameResolver(),
            new MakeFinalVisitor(),
            new AddExtraLineNumberCommentVisitor(),
            new AppendFilePathToLineCommentVisitor($twigFilePath),
            new AddLineCommentToComponentEmbedVisitor(),
            new RemoveImportsVisitor(),
            new AddTypeCommentsToTemplateVisitor($this->makeDefaultedPropsOptional(
                $twigNode,
                $this->templateContextToArrayShape->getByTemplate($templateContext, $twigFilePath),
            )),
            new IgnoreArgumentTemplateTypeOnEnsureTraversableVisitor(),
            new IgnoreDecidableIsIterableOnWithGuardVisitor(),
            new CastComponentEmbeddedTemplateIndexVisitor(),
            new RemoveLineNumberFromGetAttributeCallVisitor(),
            new RemoveStringCastFromYieldVisitor(),
            new AddGetExtensionMethodVisitor(),
            new RefactorExtensionCallVisitor(),
            ...(Environment::MAJOR_VERSION >= 4 ? [new RefactorLoopClosureVisitor()] : []),
        );

        $phpSource = $this->prettyPrinter->prettyPrintFile($stmts);

        $phpFile = Path::join($targetDirectory, sprintf(
            '%s.%s.php',
            basename($twigFilePath),
            hash('crc32', $twigFilePath),
        ));

        $this->filesystem->dumpFile(
            $phpFile,
            $phpSource,
        );

        return new CompilationResult(
            $twigFilePath,
            $phpFile,
        );
    }

    /**
     * A prop declared with a default in `{% props %}` is optional by contract:
     * even when every current usage site passes it, a future one may omit it.
     * Marking the key optional in the collected context keeps the compiled
     * `$context['prop'] ??= default;` meaningful, so the prop's type includes
     * the default instead of degrading guards like `{% if prop is not null %}`
     * into always-false comparisons.
     */
    private function makeDefaultedPropsOptional(ModuleNode $twigNode, ArrayShapeNode $context): ArrayShapeNode
    {
        $propsNode = $this->nodeFinder->findFirstInstanceOf($twigNode, AnalyzableComponentPropsNode::class);

        if ( ! $propsNode instanceof AnalyzableComponentPropsNode) {
            return $context;
        }

        $namesWithDefault = $propsNode->getNamesWithDefault();

        if ($namesWithDefault === []) {
            return $context;
        }

        $items = array_map(
            function (ArrayShapeItemNode $item) use ($namesWithDefault) {
                $name = match (true) {
                    $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                    $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                    default => null,
                };

                if ($item->optional || $name === null || ! in_array($name, $namesWithDefault, true)) {
                    return $item;
                }

                return new ArrayShapeItemNode($item->keyName, true, $item->valueType);
            },
            $context->items,
        );

        return $context->sealed
            ? ArrayShapeNode::createSealed($items, $context->kind)
            : ArrayShapeNode::createUnsealed($items, $context->unsealedType, $context->kind);
    }

    /**
     * @param array<Node> $stmts
     *
     * @return array<Node>
     */
    private function applyVisitors(array $stmts, NodeVisitor ...$visitors): array
    {
        foreach ($visitors as $visitor) {
            $nodeTraverser = new NodeTraverser();
            $nodeTraverser->addVisitor($visitor);
            $stmts = $nodeTraverser->traverse($stmts);
        }

        return $stmts;
    }
}
