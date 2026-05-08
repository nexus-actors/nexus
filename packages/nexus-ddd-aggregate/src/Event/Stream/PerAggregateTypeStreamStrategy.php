<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Stream;

use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-api
 *
 * Per-aggregate-type stream strategy: each aggregate type gets its own
 * logical stream named `ddd_events_<aggregate_short_snake_case>`.
 * Better hot-write distribution than SingleStreamStrategy at the cost of
 * more schema migrations to coordinate. Opt-in for high-write systems.
 *
 * The aggregate's class short-name (no namespace) is converted to
 * snake_case: `App\Order` → `order`, `App\CustomerAccount` → `customer_account`.
 */
final readonly class PerAggregateTypeStreamStrategy implements StreamStrategy
{
    #[Override]
    public function streamFor(string $aggregateClass, Identifier $id): StreamName
    {
        $shortName = self::shortName($aggregateClass);

        return new StreamName('ddd_events_' . self::snakeCase($shortName));
    }

    /** @param class-string $fqcn */
    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false
            ? $fqcn
            : substr($fqcn, $pos + 1);
    }

    /**
     * Converts CamelCase to snake_case: `CustomerAccount` → `customer_account`.
     */
    private static function snakeCase(string $input): string
    {
        $withSeparators = preg_replace('/(?<!^)([A-Z])/', '_$1', $input);

        return strtolower($withSeparators ?? $input);
    }
}
