<?php

declare(strict_types=1);

use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\UX\TwigComponent\Twig\ComponentExtension;
use Symfony\UX\TwigComponent\Twig\ComponentLexer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(rootPath: __DIR__);
$loader->addPath(__DIR__);
$loader->addPath(__DIR__ . '/EndToEnd', 'EndToEnd');

// The built-in form themes, as registered by FrameworkBundle when symfony/form
// is installed (e.g. `{% use 'form_div_layout.html.twig' %}` in a form theme).
$loader->addPath(dirname(__DIR__) . '/vendor/symfony/twig-bridge/Resources/views/Form');

$environment = new Environment($loader);
$environment->addGlobal('app', new AppVariable());

// form_widget()/form_label()/... functions, as registered by TwigBundle.
$environment->addExtension(new FormExtension());

// TwigComponent's `<twig:x>` syntax, as registered by TwigComponentBundle.
$environment->addExtension(new ComponentExtension());
$environment->setLexer(new ComponentLexer($environment));

return $environment;
