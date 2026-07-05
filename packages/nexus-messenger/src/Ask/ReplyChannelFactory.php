<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

/**
 * Creates reply channels for ask/reply over Messenger transports.
 *
 * Example:
 * ```php
 * $channel = $factory->create();
 * ```
 *
 * @psalm-api
 */
interface ReplyChannelFactory
{
    public function create(): ReplyChannel;
}
