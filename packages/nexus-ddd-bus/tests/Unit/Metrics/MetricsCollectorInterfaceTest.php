<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Metrics;

use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class MetricsCollectorInterfaceTest extends TestCase
{
    #[Test]
    public function declaresCountHistogramAndGaugeWithVoidReturns(): void
    {
        $reflection = new ReflectionClass(MetricsCollector::class);

        foreach (['count', 'gauge', 'histogram'] as $methodName) {
            self::assertTrue($reflection->hasMethod($methodName), "missing {$methodName}()");
            $method = $reflection->getMethod($methodName);
            $returnType = $method->getReturnType();
            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
            self::assertSame('void', $returnType->getName());
        }
    }
}
