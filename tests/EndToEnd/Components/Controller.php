<?php

declare(strict_types=1);

namespace EndToEnd\Components;

use Twig\Environment;

final class Controller
{
    public function homepage(Environment $environment, ?string $name): string
    {
        return $environment->render('EndToEnd/Components/homepage.twig', [
            'body' => 'Welcome to the homepage',
            'name' => $name,
        ]);
    }

    public function page(Environment $environment, int $total): string
    {
        return $environment->render('EndToEnd/Components/page.twig', [
            'total' => $total,
        ]);
    }
}
