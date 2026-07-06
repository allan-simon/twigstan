<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection;

use LogicException;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Form\FormView;
use TwigStan\Twig\Metadata\MetadataRegistry;
use TwigStan\Twig\TwigFileCanonicalizer;

/**
 * Provides the context that Symfony's FormRenderer injects into form theme blocks.
 *
 * A form theme has no render point: its blocks are called at runtime by
 * FormRenderer::renderBlock() with the FormView variables as context. That
 * contract is defined by symfony/form and is the same for every block of the
 * theme, whatever its name:
 *
 * - BaseType::buildView(): form, id, name, full_name, disabled, label,
 *   label_format, label_html, attr, block_prefixes, unique_block_prefix,
 *   row_attr, translation_domain, label_translation_parameters,
 *   attr_translation_parameters, priority, cache_key;
 * - FormType::buildView(): errors, valid, value, data, required, label_attr,
 *   help, help_attr, help_html, help_translation_parameters, compound, method,
 *   action, submitted; FormType::finishView(): multipart.
 *
 * Two extras complete the contract of the base theme (form_div_layout.html.twig,
 * which every detected theme builds on):
 * - widget_attr: set by its form_row block before dispatching to form_row_render;
 * - type?: optional because only set by button/widget blocks (usually guarded
 *   with `type|default(...)`).
 *
 * Type-specific variables (choices, checked, expanded, ...) and ad-hoc variables
 * passed to form_widget()/form_row() are NOT part of the contract: a block that
 * reads them must guard with `|default()` — exactly like at runtime, where they
 * may be absent.
 *
 * A template is detected as a form theme when its `{% use %}`/`{% extends %}`
 * chain reaches one of the built-in themes shipped in symfony/twig-bridge
 * (Resources/views/Form). Standalone themes written from scratch are not
 * detected; `{% form_theme form _self %}` inside a page template is not either.
 */
final class FormThemeContext
{
    private const string BRIDGE_FORM_THEME_DIRECTORY = 'symfony/twig-bridge/Resources/views/Form/';

    private const string CONTEXT = <<<'CONTEXT'
        array{
            form: Symfony\Component\Form\FormView,
            id: string,
            name: string,
            full_name: string,
            disabled: bool,
            label: Symfony\Contracts\Translation\TranslatableInterface|string|false|null,
            label_format: string|null,
            label_html: bool,
            multipart: bool,
            attr: array<string, mixed>,
            block_prefixes: list<string>,
            unique_block_prefix: string,
            row_attr: array<string, mixed>,
            translation_domain: string|false|null,
            label_translation_parameters: array<string, mixed>,
            attr_translation_parameters: array<string, mixed>,
            priority: int,
            cache_key: string,
            errors: Symfony\Component\Form\FormErrorIterator<Symfony\Component\Form\FormError>,
            valid: bool,
            value: mixed,
            data: mixed,
            required: bool,
            label_attr: array<string, mixed>,
            help: Symfony\Contracts\Translation\TranslatableInterface|string|null,
            help_attr: array<string, mixed>,
            help_html: bool,
            help_translation_parameters: array<string, mixed>,
            compound: bool,
            method: string,
            action: string,
            submitted: bool,
            widget_attr: array{attr: array<string, mixed>},
            type?: string
        }
        CONTEXT;

    private ?ArrayShapeNode $shape = null;

    /**
     * @var array<string, bool>
     */
    private array $detected = [];

    public function __construct(
        private readonly MetadataRegistry $metadataRegistry,
        private readonly TwigFileCanonicalizer $twigFileCanonicalizer,
        private readonly PhpDocParser $phpDocParser,
        private readonly Lexer $lexer,
    ) {}

    /**
     * Returns the FormRenderer contract when the template is a form theme, null otherwise.
     */
    public function getContext(string $twigFilePath): ?ArrayShapeNode
    {
        if ( ! class_exists(FormView::class)) {
            return null;
        }

        if ( ! $this->isFormTheme($twigFilePath)) {
            return null;
        }

        return $this->shape ??= $this->parseArrayShape(self::CONTEXT);
    }

    /**
     * @param array<string, true> $visited
     */
    private function isFormTheme(string $twigFilePath, array $visited = []): bool
    {
        if (isset($this->detected[$twigFilePath])) {
            return $this->detected[$twigFilePath];
        }

        if (isset($visited[$twigFilePath])) {
            return false;
        }

        $visited[$twigFilePath] = true;

        if (str_contains(Path::normalize($twigFilePath), self::BRIDGE_FORM_THEME_DIRECTORY)) {
            return $this->detected[$twigFilePath] = true;
        }

        $metadata = $this->metadataRegistry->getMetadata($twigFilePath);

        foreach ([...array_column($metadata->traits, 'name'), ...$metadata->parents] as $name) {
            // Dynamic names (e.g. `{% extends someVariable %}`) cannot be resolved.
            if (str_starts_with($name, '$')) {
                continue;
            }

            $path = $this->twigFileCanonicalizer->absolute($name);

            if ( ! is_file($path)) {
                continue;
            }

            if ($this->isFormTheme(Path::canonicalize($path), $visited)) {
                return $this->detected[$twigFilePath] = true;
            }
        }

        return $this->detected[$twigFilePath] = false;
    }

    private function parseArrayShape(string $context): ArrayShapeNode
    {
        $phpDocNode = $this->phpDocParser->parseTagValue(
            new TokenIterator($this->lexer->tokenize($context)),
            '@var',
        );

        if ( ! $phpDocNode instanceof VarTagValueNode || ! $phpDocNode->type instanceof ArrayShapeNode) {
            throw new LogicException('The form theme context is not a valid array shape.');
        }

        return $phpDocNode->type;
    }
}
