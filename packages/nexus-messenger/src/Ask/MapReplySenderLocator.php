<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Override;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Static map locator: logical channel name → configured sender.
 *
 * Example:
 * ```php
 * $locator = new MapReplySenderLocator(['orders-replies' => $ordersReplySender]);
 * ```
 *
 * @psalm-api
 */
final readonly class MapReplySenderLocator implements ReplySenderLocator
{
    /**
     * @param array<string, SenderInterface> $senders
     */
    public function __construct(private array $senders)
    {
    }

    #[Override]
    public function senderFor(string $channel): ?SenderInterface
    {
        return $this->senders[$channel] ?? null;
    }
}
