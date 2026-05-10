<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

/**
 * @psalm-api
 *
 * Application-supplied principal abstraction. Adopters provide a concrete
 * Principal implementation backed by their auth system (Symfony Security
 * UserInterface, JWT claims, custom).
 *
 * The framework never persists or serializes a Principal — adopters keep
 * lifecycle ownership.
 */
interface Principal
{
    public function id(): string;
}
