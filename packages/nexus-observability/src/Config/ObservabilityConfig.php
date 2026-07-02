<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Config;

use function explode;
use function in_array;
use function is_numeric;
use function str_contains;
use function strtolower;
use function trim;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Vendor-neutral observability configuration. Pure value object; the OTEL
 * bridge consumes it to build real providers. `fromEnv()` reads the standard
 * OpenTelemetry environment variables from an injected map (kept pure for
 * testability).
 */
final readonly class ObservabilityConfig
{
    /**
     * @param array<string, string> $resourceAttributes
     */
    public function __construct(
        public bool $enabled,
        public string $serviceName,
        public ?string $exporterEndpoint,
        public string $exporterProtocol,
        public string $sampler,
        public ?float $samplerArg,
        public bool $tracesEnabled,
        public bool $metricsEnabled,
        public bool $logsEnabled,
        public array $resourceAttributes,
    ) {}

    public static function disabled(): self
    {
        return new self(
            enabled: false,
            serviceName: 'nexus',
            exporterEndpoint: null,
            exporterProtocol: 'http/protobuf',
            sampler: 'parentbased_always_on',
            samplerArg: null,
            tracesEnabled: true,
            metricsEnabled: true,
            logsEnabled: true,
            resourceAttributes: [],
        );
    }

    public static function enabled(string $serviceName): self
    {
        return self::disabled()->withServiceName($serviceName)->withEnabled(true);
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env): self
    {
        $disabled = in_array(strtolower($env['OTEL_SDK_DISABLED'] ?? 'false'), ['1', 'true'], true);

        $samplerArg = isset($env['OTEL_TRACES_SAMPLER_ARG']) && is_numeric($env['OTEL_TRACES_SAMPLER_ARG'])
            ? (float) $env['OTEL_TRACES_SAMPLER_ARG']
            : null;

        return new self(
            enabled: !$disabled,
            serviceName: $env['OTEL_SERVICE_NAME'] ?? 'nexus',
            exporterEndpoint: $env['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? null,
            exporterProtocol: $env['OTEL_EXPORTER_OTLP_PROTOCOL'] ?? 'http/protobuf',
            sampler: $env['OTEL_TRACES_SAMPLER'] ?? 'parentbased_always_on',
            samplerArg: $samplerArg,
            tracesEnabled: true,
            metricsEnabled: true,
            logsEnabled: true,
            resourceAttributes: self::parseResourceAttributes($env['OTEL_RESOURCE_ATTRIBUTES'] ?? ''),
        );
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            enabled: $enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withServiceName(string $serviceName): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withExporterEndpoint(?string $exporterEndpoint): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withSampler(string $sampler, ?float $samplerArg): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $sampler,
            samplerArg: $samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function parseResourceAttributes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $attributes = [];

        foreach (explode(',', $raw) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $attributes[trim($key)] = trim($value);
        }

        return $attributes;
    }
}
