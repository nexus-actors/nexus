<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Single validation rule violation. Identifies what failed (`code`), why
 * (`message`), and where (`path` — typically the offending field name or
 * dot path inside the message).
 */
final readonly class Violation
{
    public function __construct(public string $code, public string $message, public string $path) {}

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->message === $other->message
            && $this->path === $other->path;
    }
}
