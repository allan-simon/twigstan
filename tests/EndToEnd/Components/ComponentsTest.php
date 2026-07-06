<?php

declare(strict_types=1);

namespace TwigStan\EndToEnd\Components;

use TwigStan\EndToEnd\AbstractEndToEndTestCase;

final class ComponentsTest extends AbstractEndToEndTestCase
{
    public function test(): void
    {
        parent::runAnalysis(__DIR__);
    }
}
