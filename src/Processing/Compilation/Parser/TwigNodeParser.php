<?php

declare(strict_types=1);

namespace TwigStan\Processing\Compilation\Parser;

use ReflectionProperty;
use Symfony\UX\TwigComponent\Twig\ComponentTokenParser;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Node\ModuleNode;
use TwigStan\Twig\TwigFileCanonicalizer;

final readonly class TwigNodeParser
{
    public function __construct(
        private Environment $twig,
        private TwigFileCanonicalizer $twigFileCanonicalizer,
    ) {}

    /**
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function parse(ModuleNode | string $template): ModuleNode
    {
        if ($template instanceof ModuleNode) {
            return $template;
        }

        $template = $this->twigFileCanonicalizer->canonicalize($template);

        $source = $this->twig->getLoader()->getSourceContext($template);

        $stream = $this->twig->tokenize($source);

        $this->stabilizeComponentEmbeddedTemplateIndexes();

        $ast = $this->twig->parse($stream);

        return $ast;
    }

    /**
     * TwigComponent generates the embedded template index of a `<twig:x>` tag
     * as crc32(file-line) followed by a counter that lives in the token parser
     * and is never reset: recompiling the same template in the same process —
     * which TwigStan's fixed-point loop does — would shift every index. Reset
     * the counter before each root parse so the index only depends on the
     * template source and stays stable across runs.
     */
    private function stabilizeComponentEmbeddedTemplateIndexes(): void
    {
        foreach ($this->twig->getTokenParsers() as $tokenParser) {
            if ( ! $tokenParser instanceof ComponentTokenParser) {
                continue;
            }

            (new ReflectionProperty(ComponentTokenParser::class, 'lineAndFileCounts'))->setValue($tokenParser, []);
        }
    }
}
