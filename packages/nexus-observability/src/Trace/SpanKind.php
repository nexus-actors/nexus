<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/** @psalm-api */
enum SpanKind: string
{
    case Internal = 'internal';
    case Server = 'server';
    case Client = 'client';
    case Producer = 'producer';
    case Consumer = 'consumer';
}
