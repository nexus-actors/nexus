---
sidebar_position: 2
title: Quick Start
---

# Quick Start

Add OTel traces and metrics to a Nexus application in five steps.

## Prerequisites

- PHP 8.5+
- Composer
- Docker (for running a local OTel Collector + Jaeger during development)

## Step 1: Install

```bash
composer require nexus-actors/observability-otel
```

This pulls in `nexus-observability` (core contracts) and `nexus-observability-otel` (OTel SDK wiring) together with the OpenTelemetry SDK for PHP.

## Step 2: Set environment variables

```bash
export OTEL_SERVICE_NAME=my-app
export OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
export OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
export OTEL_TRACES_SAMPLER=parentbased_always_on
```

These are the only variables you need to get started locally. See [Configuration](./configuration.md) for the full list.

## Step 3: Wire observability into the bootstrap

```php title="bootstrap.php" verify:lint-only
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Observability\ObservabilityConfig;
use Monadial\Nexus\Observability\OTel\ObservabilityFactory;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

NexusApp::create('my-app')
    ->withObservability(ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv($_SERVER)))
    ->run(new FiberRuntime());
```

That is the only change required. Every actor message processed by `NexusApp` will now produce a span.

## Step 4: Run a local collector and Jaeger

```yaml title="compose.yaml"
services:
  otel-collector:
    image: otel/opentelemetry-collector-contrib:latest
    command: ["--config=/etc/otel/config.yaml"]
    volumes:
      - ./otel-collector-config.yaml:/etc/otel/config.yaml
    ports:
      - "4317:4317"   # OTLP gRPC
      - "4318:4318"   # OTLP HTTP

  jaeger:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686" # Jaeger UI
      - "14250:14250" # Jaeger gRPC
```

```yaml title="otel-collector-config.yaml"
receivers:
  otlp:
    protocols:
      grpc:
        endpoint: 0.0.0.0:4317
      http:
        endpoint: 0.0.0.0:4318

exporters:
  jaeger:
    endpoint: jaeger:14250
    tls:
      insecure: true
  debug:
    verbosity: normal

service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [jaeger, debug]
    metrics:
      receivers: [otlp]
      exporters: [debug]
```

Start the stack:

```bash
docker compose up -d otel-collector jaeger
```

## Step 5: Verify

Run your application and open Jaeger at [http://localhost:16686](http://localhost:16686). Select your service name from the dropdown and click **Find Traces**. You should see one trace per actor message processed.

:::note
For production, replace the local collector endpoint with your observability vendor:
- **Grafana Cloud**: `https://otlp-gateway-prod-<region>.grafana.net/otlp`
- **Honeycomb**: `https://api.honeycomb.io`
- **Datadog**: `https://api.datadoghq.com/api/intake/otlp`

Set `OTEL_EXPORTER_OTLP_HEADERS` for vendor authentication (e.g., `Authorization=Basic ...`).
:::
