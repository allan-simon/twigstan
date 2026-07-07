<?php

declare(strict_types=1);

namespace EndToEnd\ComponentsSetInBlock;

use Twig\Environment;

final class Controller
{
    /**
     * @param list<int> $transactions
     * @param list<string> $movements
     */
    public function page(Environment $environment, bool $grouped, array $transactions, array $movements): string
    {
        return $environment->render('EndToEnd/ComponentsSetInBlock/page.twig', [
            'view' => $grouped ? 'groupe' : 'liste',
            'transactions' => $transactions,
            'movements' => $movements,
        ]);
    }
}
