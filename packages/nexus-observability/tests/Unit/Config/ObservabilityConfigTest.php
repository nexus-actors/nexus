<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Config;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityConfig::class)]
final class ObservabilityConfigTest extends TestCase
{
    #[Test]
    public function disabledHasSaneDefaults(): void
    {
        $config = ObservabilityConfig::disabled();

        self::assertFalse($config->enabled);
        self::assertSame('parentbased_always_on', $config->sampler);
        self::assertSame('http/protobuf', $config->exporterProtocol);
    }

    #[Test]
    public function fromEnvEnablesByDefault(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector:4318',
            'OTEL_SERVICE_NAME' => 'orders',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('orders', $config->serviceName);
        self::assertSame('http://collector:4318', $config->exporterEndpoint);
    }

    #[Test]
    public function fromEnvHonorsSdkDisabled(): void
    {
        $config = ObservabilityConfig::fromEnv(['OTEL_SDK_DISABLED' => 'true']);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromEnvParsesExporterTimeoutMillis(): void
    {
        $config = ObservabilityConfig::fromEnv(['OTEL_EXPORTER_OTLP_TIMEOUT' => '5000']);

        self::assertSame(5000, $config->exporterTimeoutMillis);
    }

    #[Test]
    public function exporterTimeoutDefaultsToNullAndIgnoresNonNumericValues(): void
    {
        self::assertNull(ObservabilityConfig::fromEnv([])->exporterTimeoutMillis);
        self::assertNull(ObservabilityConfig::fromEnv(['OTEL_EXPORTER_OTLP_TIMEOUT' => 'abc'])->exporterTimeoutMillis);
    }

    #[Test]
    public function withExporterTimeoutMillisReturnsNewInstanceCarryingTheValue(): void
    {
        $config = ObservabilityConfig::enabled('orders')->withExporterTimeoutMillis(2500);

        self::assertSame(2500, $config->exporterTimeoutMillis);
        // Other withers must carry the timeout forward.
        self::assertSame(2500, $config->withServiceName('billing')->exporterTimeoutMillis);
    }

    #[Test]
    public function fromEnvParsesResourceAttributesAndSampler(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment=prod,team=payments',
            'OTEL_TRACES_SAMPLER' => 'parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0.25',
        ]);

        self::assertSame('parentbased_traceidratio', $config->sampler);
        self::assertSame(0.25, $config->samplerArg);
        self::assertSame(
            ['deployment.environment' => 'prod', 'team' => 'payments'],
            $config->resourceAttributes,
        );
    }

    #[Test]
    public function fromEnvHonorsSdkDisabledNumeric(): void
    {
        $config = ObservabilityConfig::fromEnv(['OTEL_SDK_DISABLED' => '1']);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function withExporterEndpointReturnsNewImmutableInstance(): void
    {
        $base = ObservabilityConfig::enabled('svc');
        $changed = $base->withExporterEndpoint('http://collector:4318');

        self::assertSame('http://collector:4318', $changed->exporterEndpoint);
        self::assertNull($base->exporterEndpoint);
    }

    #[Test]
    public function fromEnvYieldsNullSamplerArgForNonNumericInput(): void
    {
        $config = ObservabilityConfig::fromEnv(['OTEL_TRACES_SAMPLER_ARG' => 'not-a-number']);

        self::assertNull($config->samplerArg);
    }

    #[Test]
    public function withersReturnNewInstances(): void
    {
        $base = ObservabilityConfig::enabled('svc');
        $changed = $base->withSampler('always_on', null)->withServiceName('renamed');

        self::assertSame('svc', $base->serviceName);
        self::assertSame('renamed', $changed->serviceName);
        self::assertSame('always_on', $changed->sampler);
    }

    #[Test]
    public function asyncExportDefaultsToFalse(): void
    {
        self::assertFalse(ObservabilityConfig::enabled('svc')->asyncExport);
    }

    #[Test]
    public function withAsyncExportEnablesTheFlag(): void
    {
        $config = ObservabilityConfig::enabled('svc')->withAsyncExport(true);

        self::assertTrue($config->asyncExport);
    }

    #[Test]
    public function fromEnvReadsAsyncExportFlag(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://localhost:4318',
            'OTEL_NEXUS_ASYNC_EXPORT' => 'true',
            'OTEL_SERVICE_NAME' => 'svc',
        ]);

        self::assertTrue($config->asyncExport);
    }
}
