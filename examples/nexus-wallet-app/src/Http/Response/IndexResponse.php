<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Response;

/** @psalm-api */
final readonly class IndexResponse
{
    /**
     * @param list<IndexLink> $links
     */
    public function __construct(public string $name, public int $thread, public array $links) {}
}
