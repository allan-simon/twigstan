<?php

declare(strict_types=1);

namespace TwigStan\EndToEnd\FormThemes;

use TwigStan\EndToEnd\AbstractEndToEndTestCase;

final class FormThemesTest extends AbstractEndToEndTestCase
{
    public function test(): void
    {
        parent::runAnalysis(__DIR__);
    }
}
