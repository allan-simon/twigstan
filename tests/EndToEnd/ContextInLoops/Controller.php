<?php

declare(strict_types=1);

namespace EndToEnd\ContextInLoops;

use Twig\Environment;
use TwigStan\Fixtures\User;

final class Controller
{
    /**
     * @param list<array{title: string, users: list<User>}> $groups
     */
    public function page(Environment $environment, array $groups, string $heading): string
    {
        return $environment->render('EndToEnd/ContextInLoops/page.twig', [
            'groups' => $groups,
            'heading' => $heading,
        ]);
    }
}
