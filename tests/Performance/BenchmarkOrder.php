<?php

declare(strict_types=1);

/**
 * Benchmark message fixture for hotpath_breakdown.php.
 *
 * Mirrors the world-benchmark order shape: a customer order with a line-item
 * basket and a pricing tier (bronze/silver/gold). Lives in the global
 * namespace and is loaded via require_once because hotpath_breakdown.php is
 * a standalone CLI script outside the autoloader's PSR-4 roots.
 */
final readonly class BenchmarkOrder
{
    /**
     * @param array<int, array{name: string, price: float, qty: int}> $items
     */
    public function __construct(
        public int $customerId,
        public array $items,
        public string $orderId,
        public string $tier,
    ) {}
}
