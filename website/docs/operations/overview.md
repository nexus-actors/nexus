---
title: Operations
sidebar_position: 1
related:
  - operations/deployment
  - operations/observability
  - operations/performance-tuning
  - scaling/overview
---

# Operations

This section covers what happens after you ship: how to deploy Nexus to production, how to observe a running system, and how to tune performance when defaults are not enough.

## What's in this section

Four pages cover the operational surface of a Nexus application:

- **[Deployment](deployment.md)** — OPcache, health checks, graceful shutdown, reverse proxy configuration, process supervision, and resource limits. Also links to platform-specific drill-ins for [Docker](deployment/docker.md), [systemd](deployment/systemd.md), and [Kubernetes](deployment/kubernetes.md).
- **[Observability](observability.md)** — PSR-3 logging, per-request MDC context, access logging, async log sinks, and PSR-14 event dispatch for integration with OpenTelemetry and custom metrics tools.
- **[Performance tuning](performance-tuning.md)** — Framework pre-binding, OPcache and JIT configuration, Swoole server settings, and Linux TCP kernel parameters. Includes measured benchmark data.

## Prerequisites

The operations section assumes you have a running Nexus HTTP application. If you have not yet built one, start with [Getting started](../getting-started/quick-start.md) and the [HTTP overview](../http/overview.md).

For worker pool deployments (multi-core scaling), the [Scaling section](../scaling/overview.md) covers worker pool architecture before you apply the deployment and observability patterns here.

## See also

- [Scaling overview](../scaling/overview.md) — worker pool architecture and thread-safe deployment
- [HTTP servers](../http/servers.md) — Swoole worker mode vs thread mode tradeoffs
- [nexus-logger package](../packages/logger.md) — actor-backed async logger
