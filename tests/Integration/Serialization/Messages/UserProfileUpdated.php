<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('user.profile.updated')]
final readonly class UserProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $name,
        public ?string $email,
        public ?Address $address,
    ) {}
}
