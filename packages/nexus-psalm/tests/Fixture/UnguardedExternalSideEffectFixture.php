<?php

declare(strict_types=1);

// phpcs:disable

namespace Stripe;

/**
 * Fixture stub matching the rule's allow-list (`stripe\*`).
 *
 * @psalm-suppress UnusedClass
 */
final class FixtureCharges
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function create(string $arg): string
    {
        return $arg;
    }
}

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Bus\Attribute\Handler;
use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Stripe\FixtureCharges;

/** @psalm-immutable */
final readonly class UnguardedCommand implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * @psalm-immutable
 */
#[IdempotencyKey(field: 'requestId')]
final readonly class GuardedCommand implements Command
{
    public function __construct(public string $requestId, public string $payload) {}
}

/**
 * Bad: handler invokes external Stripe SDK; command has no #[IdempotencyKey].
 */
final class BadUnguardedHandler implements CommandHandler
{
    public function __construct(private readonly FixtureCharges $stripe) {}

    public function __invoke(UnguardedCommand $command): void
    {
        $this->stripe->create($command->payload);
    }
}

/**
 * Good: external call is allowed when the command has #[IdempotencyKey].
 */
final class GoodGuardedHandler implements CommandHandler
{
    public function __construct(private readonly FixtureCharges $stripe) {}

    public function __invoke(GuardedCommand $command): void
    {
        $this->stripe->create($command->payload);
    }
}

/**
 * Bad: #[Handler]-attributed method calling external API; no IdempotencyKey.
 */
final class BadUnguardedMultiService
{
    public function __construct(private readonly FixtureCharges $stripe) {}

    #[Handler]
    public function place(UnguardedCommand $command): void
    {
        $this->stripe->create($command->payload);
    }
}
