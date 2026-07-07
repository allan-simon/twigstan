<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Printer\Printer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use TwigStan\PHP\PrettyPrinter;
use TwigStan\PHP\StrictPhpParser;
use TwigStan\PHPStan\Analysis\CollectedData;
use TwigStan\PHPStan\Collector\BlockContextCollector;
use TwigStan\PHPStan\Collector\ComponentEmbedContextCollector;
use TwigStan\PHPStan\Collector\MacroCollector;
use TwigStan\Processing\Flattening\FlatteningResultCollection;
use TwigStan\Processing\ScopeInjection\PhpVisitor\InjectComponentEmbeddedContextVisitor;
use TwigStan\Processing\ScopeInjection\PhpVisitor\InjectContextVisitor;
use TwigStan\Processing\ScopeInjection\PhpVisitor\InjectMacroVisitor;
use TwigStan\Processing\ScopeInjection\PhpVisitor\PhpToTemplateLinesNodeVisitor;
use TwigStan\Twig\SourceLocation;

/**
 * @phpstan-type ContextData = array{
 *      blockName: null|string,
 *      sourceLocation: SourceLocation,
 *      context: ArrayShapeNode,
 *      parent: bool,
 *      relatedBlockName: null|string,
 *      relatedParent: bool,
 * }
 * @phpstan-type EmbedContextData = array{
 *      blockName: null|string,
 *      sourceLocation: SourceLocation,
 *      context: ArrayShapeNode,
 *      parent: bool,
 *      relatedBlockName: null|string,
 *      relatedParent: bool,
 *      relatedEmbeddedTemplateIndex: null|int,
 * }
 */
final class TwigScopeInjector
{
    /**
     * @var array<string, null|ArrayShapeNode>
     */
    private array $cachedParentContext = [];

    public function __construct(
        private readonly PrettyPrinter $prettyPrinter,
        private readonly Filesystem $filesystem,
        private readonly StrictPhpParser $phpParser,
        private readonly ArrayShapeMerger $arrayShapeMerger,
        private readonly PhpDocParser $phpDocParser,
        private readonly Lexer $lexer,
        private readonly FormThemeContext $formThemeContext,
    ) {}

    /**
     * The analysis-phase captures are keyed by Twig file and embedded template index.
     *
     * @param list<CollectedData> $collectedData
     * @param array<string, array<int, array{sourceLocation: SourceLocation, context: string}>> $componentEmbedContextFromAnalysis
     */
    public function inject(array $collectedData, FlatteningResultCollection $collection, string $targetDirectory, int $run, array $componentEmbedContextFromAnalysis = []): ScopeInjectionResultCollection
    {
        $targetDirectory = Path::join($targetDirectory, (string) $run);

        $this->filesystem->mkdir($targetDirectory);

        $contextBeforeBlockByFilename = [];
        $componentEmbedContextByFilename = [];
        $macros = [];

        foreach ($collectedData as $data) {
            if ($data->collecterType === MacroCollector::class) {
                // PHPStan aggregates collector results per file: $data->data is a list of
                // MacroData node results.
                foreach ($data->data as $macroData) {
                    $macros[$data->filePath] = $macroData['macros'];
                }
            } elseif ($data->collecterType === BlockContextCollector::class) {
                // PHPStan aggregates collector results per file: $data->data is a list of
                // ContextData node results.
                foreach ($data->data as $blockData) {
                    $context = $this->parseArrayShape($blockData['context']) ?? ArrayShapeNode::createSealed([]);

                    $sourceLocation = SourceLocation::decode($blockData['sourceLocation']);

                    $contextBeforeBlockByFilename[$sourceLocation->last()->fileName][] = [
                        'blockName' => $blockData['blockName'],
                        'sourceLocation' => $sourceLocation,
                        'context' => $context,
                        'parent' => $blockData['parent'],
                        'relatedBlockName' => $blockData['relatedBlockName'],
                        'relatedParent' => $blockData['relatedParent'],
                    ];
                }
            } elseif ($data->collecterType === ComponentEmbedContextCollector::class) {
                // PHPStan aggregates collector results per file: $data->data is a list of
                // ComponentEmbedContextData node results.
                foreach ($data->data as $embedData) {
                    $context = $this->parseArrayShape($embedData['context']);

                    if ($context === null) {
                        continue;
                    }

                    $componentEmbedContextByFilename[$data->filePath][$embedData['embeddedTemplateIndex']] = [
                        'blockName' => null,
                        'sourceLocation' => SourceLocation::decode($embedData['sourceLocation']),
                        'context' => $context,
                        'parent' => false,
                        'relatedBlockName' => $embedData['relatedBlockName'],
                        'relatedParent' => $embedData['relatedParent'],
                        'relatedEmbeddedTemplateIndex' => $embedData['relatedEmbeddedTemplateIndex'],
                    ];
                }
            }
        }

        // A capture made during the analysis phase of a previous run is strictly
        // better informed: the host blocks had their real @param injected, so the
        // context at the usage site was complete (including `{% set %}` variables
        // set before the component). It replaces the bootstrap capture for the
        // same embedded template index — one index is one usage site — and its
        // host part is already complete, so no recursive completion is needed.
        foreach ($collection as $flatteningResult) {
            foreach ($componentEmbedContextFromAnalysis[$flatteningResult->twigFilePath] ?? [] as $embeddedTemplateIndex => $capture) {
                $context = $this->parseArrayShape($capture['context']);

                if ($context === null) {
                    continue;
                }

                $componentEmbedContextByFilename[$flatteningResult->phpFile][$embeddedTemplateIndex] = [
                    'blockName' => null,
                    'sourceLocation' => $capture['sourceLocation'],
                    'context' => $context,
                    'parent' => false,
                    'relatedBlockName' => null,
                    'relatedParent' => false,
                    'relatedEmbeddedTemplateIndex' => null,
                ];
            }
        }

        $contextBeforeBlock = [];
        $this->cachedParentContext = [];
        foreach ($contextBeforeBlockByFilename as $contexts) {
            foreach ($contexts as $context) {
                $contextBeforeBlock[] = $this->getRecursiveContext($context, $contextBeforeBlockByFilename);
            }
        }

        // When the component usage sits inside a block (or inside the body of
        // another component), complete the embedded context with the
        // (recursively resolved) context of that block or enclosing embed.
        $componentEmbedContext = [];
        foreach ($componentEmbedContextByFilename as $phpFile => $embedContexts) {
            foreach (array_keys($embedContexts) as $embeddedTemplateIndex) {
                $componentEmbedContext[$phpFile][$embeddedTemplateIndex] = $this->getRecursiveEmbedContext(
                    $phpFile,
                    $embeddedTemplateIndex,
                    $componentEmbedContextByFilename,
                    $contextBeforeBlockByFilename,
                );
            }
        }

        $results = new ScopeInjectionResultCollection();
        foreach ($collection as $flatteningResult) {
            $contextBeforeBlockRelatedToTemplate = array_values(array_filter(
                $contextBeforeBlock,
                fn($contextBeforeBlock) => $contextBeforeBlock['sourceLocation']->contains($flatteningResult->twigFilePath),
            ));
            $stmts = $this->applyVisitors(
                $this->phpParser->parseFile($flatteningResult->phpFile),
                new NameResolver(),
                new InjectContextVisitor(
                    $contextBeforeBlockRelatedToTemplate,
                    $this->formThemeContext->getContext($flatteningResult->twigFilePath),
                    $this->arrayShapeMerger,
                ),
                new InjectComponentEmbeddedContextVisitor(
                    $componentEmbedContext[$flatteningResult->phpFile] ?? [],
                ),
                ...isset($macros[$flatteningResult->phpFile]) ? [new InjectMacroVisitor($macros[$flatteningResult->phpFile])] : [],
            );

            $phpSource = $this->prettyPrinter->prettyPrintFile($stmts);

            $phpFile = Path::join($targetDirectory, basename($flatteningResult->phpFile));

            $this->filesystem->dumpFile(
                $phpFile,
                $phpSource,
            );

            // This is a bit inefficient, maybe we can make this smarter
            $stmts = $this->phpParser->parse($phpSource);

            $visitor = new PhpToTemplateLinesNodeVisitor();
            $this->applyVisitors($stmts, $visitor);

            $results = $results->with(new ScopeInjectionResult(
                $flatteningResult->twigFilePath,
                $phpFile,
                $visitor->getMapping(),
                array_map(
                    fn(ArrayShapeNode $context) => (new Printer())->print($context),
                    $componentEmbedContext[$flatteningResult->phpFile] ?? [],
                ),
            ));
        }

        return $results;
    }

    /**
     * Resolves the full context of an embedded component class: its own
     * collected context, completed with the context of the enclosing embed
     * (nested components) or of the enclosing block.
     *
     * @param array<string, array<int, EmbedContextData>> $componentEmbedContextByFilename
     * @param array<string, array<ContextData>> $contextBeforeBlockByFilename
     * @param array<int, true> $visited
     */
    private function getRecursiveEmbedContext(
        string $phpFile,
        int $embeddedTemplateIndex,
        array $componentEmbedContextByFilename,
        array $contextBeforeBlockByFilename,
        array $visited = [],
    ): ArrayShapeNode {
        $embedContext = $componentEmbedContextByFilename[$phpFile][$embeddedTemplateIndex];

        $relatedIndex = $embedContext['relatedEmbeddedTemplateIndex'];

        if ($relatedIndex !== null
            && $relatedIndex !== $embeddedTemplateIndex
            && ! isset($visited[$relatedIndex])
            && isset($componentEmbedContextByFilename[$phpFile][$relatedIndex])
        ) {
            $enclosingContext = $this->getRecursiveEmbedContext(
                $phpFile,
                $relatedIndex,
                $componentEmbedContextByFilename,
                $contextBeforeBlockByFilename,
                $visited + [$embeddedTemplateIndex => true],
            );

            return $this->arrayShapeMerger->merge($embedContext['context'], $enclosingContext, true);
        }

        unset($embedContext['relatedEmbeddedTemplateIndex']);

        return $this->getRecursiveContext($embedContext, $contextBeforeBlockByFilename)['context'];
    }

    /**
     * Parses a context printed by a collector back into an array shape.
     * Returns null when the context is not an array shape (e.g. a general array).
     */
    private function parseArrayShape(string $context): ?ArrayShapeNode
    {
        $phpDocNode = $this->phpDocParser->parseTagValue(
            new TokenIterator($this->lexer->tokenize($context)),
            '@var',
        );

        if ( ! $phpDocNode instanceof VarTagValueNode) {
            throw new LogicException('Invalid @var tag.');
        }

        if ( ! $phpDocNode->type instanceof ArrayShapeNode) {
            return null;
        }

        return $phpDocNode->type;
    }

    /**
     * @param ContextData $context
     * @param array<array<ContextData>> $contextBeforeBlockByFilename
     *
     * @return ContextData
     */
    private function getRecursiveContext(array $context, array $contextBeforeBlockByFilename): array
    {
        $relatedBlockName = $context['relatedBlockName'];
        $relatedParent = $context['relatedParent'];

        if ($relatedBlockName === null) {
            return $context;
        }

        // Avoid infinite loop when a block use `parent()` call.
        if ($relatedBlockName === $context['blockName']) {
            return $context;
        }

        $file = $context['sourceLocation']->last()->fileName;

        $cacheKey = sprintf('%s#%s#%d', $file, $relatedBlockName, (int) $relatedParent);

        if (array_key_exists($cacheKey, $this->cachedParentContext)) {
            $parentContext = $this->cachedParentContext[$cacheKey];
        } else {
            $parentContext = null;
            foreach ($contextBeforeBlockByFilename[$file] ?? [] as $fileContext) {
                if ($relatedBlockName !== $fileContext['blockName'] || $relatedParent !== $fileContext['parent']) {
                    continue;
                }

                $fileContext = $this->getRecursiveContext($fileContext, $contextBeforeBlockByFilename);

                if ($parentContext === null) {
                    $parentContext = $fileContext['context'];
                } else {
                    $parentContext = $this->arrayShapeMerger->merge($parentContext, $fileContext['context']);
                }
            }

            $this->cachedParentContext[$cacheKey] = $parentContext;
        }

        if ($parentContext === null) {
            return $context;
        }

        $context['context'] = $this->arrayShapeMerger->merge($context['context'], $parentContext, true);

        return $context;
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
