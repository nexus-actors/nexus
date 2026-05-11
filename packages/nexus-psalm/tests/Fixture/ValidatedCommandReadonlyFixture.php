<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Bus\Attribute\Handler;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/** @psalm-immutable */
final readonly class ValidatedReadonlyGoodCommand implements Command
{
    public function __construct(public string $payload) {}
}

final class ValidatedReadonlyBadCommand implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * Good: validated command is readonly.
 */
final class GoodValidatedHandlerService
{
    #[Handler]
    #[Validate]
    public function place(ValidatedReadonlyGoodCommand $command): void {}
}

/**
 * Bad: #[Validate] handler operates on a non-readonly command.
 */
final class BadValidatedHandlerService
{
    #[Handler]
    #[Validate]
    public function place(ValidatedReadonlyBadCommand $command): void {}
}
