<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;

final readonly class RegisterUserHandler implements CommandHandler
{
    public function __construct(private EventBus $events) {}

    public function __invoke(RegisterUser $command): void
    {
        $this->events->publishEvent(new UserRegistered($command->userId));
    }
}
