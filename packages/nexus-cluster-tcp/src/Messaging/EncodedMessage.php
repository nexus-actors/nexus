<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

/**
 * @psalm-api
 *
 * A message encoded for the wire: its registered type name plus the serialized body.
 */
final readonly class EncodedMessage
{
    public function __construct(public string $type, public string $body) {}
}
