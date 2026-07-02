---
title: Docker deployment
sidebar_position: 1
related:
  - operations/deployment
  - operations/performance-tuning
  - scaling/overview
  - scaling/bootstrap
---

# Docker deployment

Nexus ships three Dockerfile targets: `php-fiber` (development / Fiber tests), `php-swoole` (production HTTP and worker pool), and `php-full` (development with both Xdebug and Swoole). Production deployments use the `php-swoole` target.

## Dockerfile targets

| Target | Base | Extensions | Use case |
|---|---|---|---|
| `php-fiber` | PHP 8.5 non-ZTS + Xdebug | Xdebug only | Unit tests, Fiber integration, local development |
| `php-swoole` | PHP 8.5 ZTS + Swoole 6.0 | Swoole (thread mode) + OPcache + JIT | Production HTTP, worker pool |
| `php-full` | PHP 8.5 ZTS + Xdebug + Swoole | Both | Full local development with Swoole |

Build the production image:

```bash
docker build --target php-swoole -t myapp:latest .
```

## OPcache in the production image

The `php-swoole` target copies two OPcache configuration files:

```
docker/opcache.ini         → /usr/local/etc/php/conf.d/zz-opcache.ini
docker/zzz-opcache-prod.ini → /usr/local/etc/php/conf.d/zzz-opcache-prod.ini
```

PHP loads `conf.d/*.ini` in alphabetical order. The `zzz-` prefix on the prod override ensures it sorts after `zz-opcache.ini` and wins. Specifically: ASCII sorts `-` (0x2D) before `.` (0x2E), so `zz-opcache-prod.ini` would load before `zz-opcache.ini` and be overridden — the `zzz-` prefix avoids this.

The development `php-full` target copies only `docker/opcache.ini` without the prod override. Developers editing volume-mounted code expect `validate_timestamps=1` (the default in `opcache.ini`) so changes take effect without rebuilding.

## ZTS requirement for worker pool

The worker pool requires ZTS (Zend Thread Safety) PHP and Swoole compiled with `--enable-swoole-thread`. The `php-swoole` target satisfies both:

- It uses `php:8.5.7-zts` as the base image.
- Swoole is built from source with `--enable-swoole --enable-swoole-thread`.

:::caution Do not use non-ZTS PHP for worker pool
`Swoole\Thread\Pool` requires a ZTS build. Running a worker pool on a non-ZTS PHP image produces a fatal error at startup.
:::

## Sysctls and ulimits

For production containers, add TCP sysctls and file descriptor limits to your `compose.yaml` or Kubernetes pod spec:

```yaml title="compose.yaml"
php-swoole:
  build:
    target: php-swoole
  sysctls:
    net.core.somaxconn: 65535
    net.ipv4.tcp_max_syn_backlog: 65535
    net.ipv4.tcp_tw_reuse: 1
    net.ipv4.ip_local_port_range: "1024 65535"
  ulimits:
    nofile:
      soft: 65535
      hard: 65535
```

See [Performance tuning](../performance-tuning.md#kernel-tcp-sysctls) for the rationale behind each parameter.

:::note Full Docker deployment guide
A comprehensive Docker deployment guide — multi-stage production builds, layer caching strategy, image hardening, and registry publishing — is planned for a future documentation phase.
:::

## See also

- [Deployment](../deployment.md) — OPcache, health checks, graceful shutdown, and pre-flight checklist
- [Performance tuning](../performance-tuning.md) — complete tuning guide including kernel sysctls
- [systemd deployment](./systemd.md) — running Nexus under systemd
- [Kubernetes deployment](./kubernetes.md) — pod manifests, probes, and rolling updates
