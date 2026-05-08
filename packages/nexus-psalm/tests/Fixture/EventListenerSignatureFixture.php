<?php

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.Functions.DisallowEmptyFunction

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Handler\EventListener;

/** @psalm-immutable */
final readonly class ListenerEventA implements DomainEvent
{
    public function __construct(public string $payload) {}
}

/** @psalm-immutable */
final readonly class ListenerEventB implements DomainEvent
{
    public function __construct(public string $payload) {}
}

final class GoodEventListener implements EventListener
{
    public function __invoke(ListenerEventA $event): void {}
}

final class GoodEventListenerWithContext implements EventListener
{
    public function __invoke(ListenerEventB $event, MessageContext $ctx): void {}
}

final class BadEventListenerNoInvoke implements EventListener {}

final class BadEventListenerWrongReturn implements EventListener
{
    public function __invoke(ListenerEventA $event): string
    {
        return 'oops';
    }
}
