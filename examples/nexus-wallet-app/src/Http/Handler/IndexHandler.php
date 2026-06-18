<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * GET / — returns the link map. Useful for the demo and for the smoke
 * tests that probe every advertised route.
 */
final readonly class IndexHandler
{
    public function __construct(private int $workerId) {}

    public function __invoke(): ResponseInterface
    {
        return JsonResponse::ok([
            'links' => [
                ['method' => 'GET',  'href' => '/admin/wallets'],
                ['method' => 'GET',  'href' => '/health'],
                ['method' => 'GET',  'href' => '/wallet/balance'],
                ['method' => 'POST', 'href' => '/wallet/deposit'],
                ['method' => 'GET',  'href' => '/wallet/ledger'],
                ['method' => 'GET',  'href' => '/wallet/ledger/entries'],
                ['method' => 'POST', 'href' => '/wallet/ledger/record'],
                ['method' => 'POST', 'href' => '/wallet/withdraw'],
            ],
            'name' => 'nexus-wallet-app',
            'thread' => $this->workerId,
        ]);
    }
}
