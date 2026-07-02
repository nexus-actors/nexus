<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use function implode;

/** @psalm-api */
final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(string $method, string $path, public readonly array $allowed)
    {
        parent::__construct(
            405,
            "Method {$method} not allowed for {$path}; allowed: " . implode(', ', $allowed),
            ['Allow' => implode(', ', $allowed)],
        );
    }
}
