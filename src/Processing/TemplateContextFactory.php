<?php

declare(strict_types=1);

namespace TwigStan\Processing;

use TwigStan\PHPStan\Analysis\PHPStanAnalysisResult;
use TwigStan\PHPStan\Collector\TemplateContextCollector;
use TwigStan\Twig\SourceLocation;
use TwigStan\Twig\TwigFileCanonicalizer;

final readonly class TemplateContextFactory
{
    public function __construct(
        private TwigFileCanonicalizer $twigFileCanonicalizer,
    ) {}

    public function create(PHPStanAnalysisResult $analysisResult): TemplateContext
    {
        /**
         * @var array<string, array<string, array{SourceLocation, string}>> $templateContext
         */
        $templateContext = [];
        foreach ($analysisResult->collectedData as $data) {
            if (is_a($data->collecterType, TemplateContextCollector::class, true)) {
                // PHPStan aggregates collector results per file: $data->data is a list of
                // node results, and each TemplateContextCollector node result is itself
                // a list of TemplateData.
                foreach ($data->data as $nodeResults) {
                    foreach ($nodeResults as $renderData) {
                        $template = $this->twigFileCanonicalizer->absolute($renderData['template']);
                        $sourceLocation = SourceLocation::decode($renderData['sourceLocation']);

                        $templateContext[$template][$sourceLocation->getHash()] = [$sourceLocation, $renderData['context']];
                    }
                }
            }
        }

        return new TemplateContext($templateContext);
    }
}
