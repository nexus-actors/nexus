<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Messaging\Message\Command;

final readonly class RegisterUser implements Command
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {}
}
