<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use function explode;
use function implode;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function trim;

/**
 * @psalm-api
 *
 * W3C Baggage propagator (`baggage` header). Member properties (after `;`) are
 * parsed away on extract and not emitted on inject.
 *
 * @see https://www.w3.org/TR/baggage/
 */
final class BaggagePropagator implements ContextPropagator
{
    private const string BAGGAGE = 'baggage';

    public function inject(Context $context, array &$carrier): void
    {
        if ($context->baggage->isEmpty()) {
            return;
        }

        $members = [];

        foreach ($context->baggage->all() as $key => $value) {
            $members[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        $carrier[self::BAGGAGE] = implode(',', $members);
    }

    public function extract(array $carrier, ?Context $context = null): Context
    {
        $base = $context ?? Context::root();
        $header = $carrier[self::BAGGAGE] ?? null;

        if ($header === null || $header === '') {
            return $base;
        }

        $baggage = $base->baggage;

        foreach (explode(',', $header) as $member) {
            $member = trim($member);

            // Drop any member-level properties after ';'.
            $pair = explode(';', $member, 2)[0];

            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = rawurldecode(trim($key));

            if ($key === '') {
                continue;
            }

            $baggage = $baggage->with($key, rawurldecode(trim($value)));
        }

        return $base->withBaggage($baggage);
    }
}
