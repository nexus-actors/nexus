<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Runtime\Duration;

/**
 * @template T of object
 * @psalm-api
 */
interface Channel
{
    /**
     * @param T $item
     */
    public function push(object $item): bool;

    /**
     * @return T|null
     */
    public function pop(Duration $timeout): ?object;

    public function size(): int;

    public function close(): void;

    public function isClosed(): bool;
}
