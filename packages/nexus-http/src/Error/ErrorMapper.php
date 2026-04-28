<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Error;

use Monadial\Nexus\Http\RequestCtx;
use Psr\Http\Message\ResponseInterface;
use Throwable;

interface ErrorMapper
{
    public function map(Throwable $error, RequestCtx $ctx): ResponseInterface;
}
