<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Exception;

use RuntimeException;

/** @psalm-api */
final class FutureCancelledException extends RuntimeException implements FutureException
{
    public function __construct()
    {
        parent::__construct('Future was cancelled');
    }
}
