<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Handler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class AuthBeforeFixtureCommandA implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class AuthBeforeFixtureCommandB implements Command
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class AuthBeforeFixtureCommandC implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * Good: 'validation' is a real PipelineStage value.
 */
final class GoodAuthorizeStageService
{
    #[Handler]
    #[Authorize(policy: 'order.place', before: 'validation')]
    public function place(AuthBeforeFixtureCommandA $command): void {}

    #[Handler]
    #[Authorize(policy: 'order.cancel', before: 'handler')]
    public function cancel(AuthBeforeFixtureCommandB $command): void {}
}

/**
 * Bad: 'validashion' is not a PipelineStage value.
 */
final class BadAuthorizeStageService
{
    #[Handler]
    #[Authorize(policy: 'order.place', before: 'validashion')]
    public function place(AuthBeforeFixtureCommandA $command): void {}

    #[Handler]
    #[Authorize(policy: 'order.cancel', before: 'not-a-stage')]
    public function cancel(AuthBeforeFixtureCommandC $command): void {}
}
