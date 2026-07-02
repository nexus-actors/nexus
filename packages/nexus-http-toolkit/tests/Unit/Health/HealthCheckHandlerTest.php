<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Health;

use Monadial\Nexus\Http\Toolkit\Health\HealthCheck;
use Monadial\Nexus\Http\Toolkit\Health\HealthCheckHandler;
use Monadial\Nexus\Http\Toolkit\Health\HealthCheckRegistry;
use Monadial\Nexus\Http\Toolkit\Health\HealthStatus;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function json_decode;

#[CoversClass(HealthCheckHandler::class)]
#[CoversClass(HealthCheckRegistry::class)]
#[CoversClass(HealthStatus::class)]
final class HealthCheckHandlerTest extends TestCase
{
    #[Test]
    public function returns_200_when_all_checks_are_up(): void
    {
        $registry = (new HealthCheckRegistry())
            ->add(new StubCheck('database', HealthStatus::up(['latencyMs' => 1.2])))
            ->add(new StubCheck('redis', HealthStatus::up()));

        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/health');

        $response = (new HealthCheckHandler($registry))($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{status: string, checks: array<string, array{state: string}>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('up', $body['status']);
        self::assertSame('up', $body['checks']['database']['state']);
        self::assertSame('up', $body['checks']['redis']['state']);
    }

    #[Test]
    public function returns_503_when_any_check_is_down(): void
    {
        $registry = (new HealthCheckRegistry())
            ->add(new StubCheck('database', HealthStatus::up()))
            ->add(new StubCheck('redis', HealthStatus::down(['error' => 'connection refused'])));

        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/health');

        $response = (new HealthCheckHandler($registry))($request);

        self::assertSame(503, $response->getStatusCode());
        /** @var array{status: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('down', $body['status']);
    }

    #[Test]
    public function returns_200_with_degraded_when_no_down_but_at_least_one_degraded(): void
    {
        $registry = (new HealthCheckRegistry())
            ->add(new StubCheck('database', HealthStatus::up()))
            ->add(new StubCheck('cache', HealthStatus::degraded(['hitRatio' => 0.42])));

        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/health');

        $response = (new HealthCheckHandler($registry))($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{status: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('degraded', $body['status']);
    }

    #[Test]
    public function check_that_throws_is_treated_as_down(): void
    {
        $registry = (new HealthCheckRegistry())
            ->add(new ThrowingCheck('database', new RuntimeException('cannot connect')));

        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/health');

        $response = (new HealthCheckHandler($registry))($request);

        self::assertSame(503, $response->getStatusCode());
        /** @var array{checks: array{database: array{state: string, detail: array{error: string, message: string}}}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('down', $body['checks']['database']['state']);
        self::assertSame(RuntimeException::class, $body['checks']['database']['detail']['error']);
        self::assertSame('cannot connect', $body['checks']['database']['detail']['message']);
    }
}

final readonly class StubCheck implements HealthCheck
{
    public function __construct(private string $name, private HealthStatus $status) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function check(): HealthStatus
    {
        return $this->status;
    }
}

final readonly class ThrowingCheck implements HealthCheck
{
    public function __construct(private string $name, private Throwable $throwable) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function check(): HealthStatus
    {
        throw $this->throwable;
    }
}
