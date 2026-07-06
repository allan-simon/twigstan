<?php

declare(strict_types=1);

namespace TwigStan\Twig;

use Twig\Environment;

/**
 * Resolves the template of an anonymous TwigComponent from its name, e.g.
 * `registre:StockChain` to `components/registre/StockChain.html.twig`.
 *
 * Mirrors the lookup of TwigComponent's ComponentTemplateFinder. Returns null
 * when no template matches; the component is then either a class-based
 * component (out of scope for the anonymous component analysis) or a typo
 * that Twig will report at runtime.
 */
final readonly class AnonymousComponentTemplateResolver
{
    public function __construct(
        private Environment $twig,
        private string $directory,
    ) {}

    public function resolve(string $name): ?string
    {
        $loader = $this->twig->getLoader();
        $componentPath = str_replace(':', '/', $name);
        $directory = rtrim($this->directory, '/');

        $candidates = [
            sprintf('%s/%s.html.twig', $directory, $componentPath),
            sprintf('%s/%s/index.html.twig', $directory, $componentPath),
        ];

        $parts = explode('/', $componentPath, 2);

        if (count($parts) === 2) {
            $candidates[] = sprintf('@%s/components/%s.html.twig', $parts[0], $parts[1]);
            $candidates[] = sprintf('@%s/components/%s/index.html.twig', $parts[0], $parts[1]);
        }

        foreach ($candidates as $candidate) {
            if ($loader->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
