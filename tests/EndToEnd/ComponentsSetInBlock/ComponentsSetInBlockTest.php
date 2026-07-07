<?php

declare(strict_types=1);

namespace TwigStan\EndToEnd\ComponentsSetInBlock;

use TwigStan\EndToEnd\AbstractEndToEndTestCase;

final class ComponentsSetInBlockTest extends AbstractEndToEndTestCase
{
    public function test(): void
    {
        parent::runAnalysis(__DIR__);
    }
}
