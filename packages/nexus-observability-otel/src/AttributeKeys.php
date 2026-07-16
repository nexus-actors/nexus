<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

/**
 * Narrows framework attribute maps (any string keys) to the non-empty-string
 * keys the OpenTelemetry SDK instruments declare. Empty keys are dropped — the
 * OTel specification discards attributes with empty keys anyway.
 *
 * @internal
 */
final class AttributeKeys
{
    /**
     * @param array<string, scalar> $attributes
     *
     * @return array<non-empty-string, scalar>
     */
    public static function nonEmpty(array $attributes): array
    {
        $narrowed = [];

        foreach ($attributes as $key => $value) {
            if ($key === '') {
                continue;
            }

            $narrowed[$key] = $value;
        }

        return $narrowed;
    }
}
