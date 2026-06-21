---
title: systemd deployment
sidebar_position: 2
related:
  - operations/deployment
  - operations/performance-tuning
  - operations/deployment/docker
  - operations/deployment/kubernetes
---

# systemd deployment

Running Nexus under systemd requires a service unit that matches Swoole's signal handling and gives the process enough time to drain in-flight requests before the OS kills it.

## Service unit

A minimal service unit for a Swoole HTTP server:

```ini title="/etc/systemd/system/nexus-api.service"
[Unit]
Description=Nexus API server
After=network.target
Requires=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/app

ExecStart=/usr/local/bin/php /app/server.php
Restart=on-failure
RestartSec=5

KillSignal=SIGTERM
TimeoutStopSec=15

LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
```

Key settings:

- **`Type=simple`** — Correct for Swoole. Use `Type=notify` only if you wire `sd_notify(READY=1)` from within the server bootstrap.
- **`KillSignal=SIGTERM`** — Swoole's signal handler catches `SIGTERM` and starts the graceful drain. Using `SIGKILL` here bypasses drain entirely.
- **`TimeoutStopSec=15`** — Must be greater than or equal to your application's `shutdownTimeout`. Set `shutdownTimeout(Duration::seconds(12))` for a 15-second stop timeout (leaves a 3-second OS-level buffer).
- **`LimitNOFILE=65536`** — Raises the file descriptor limit to support high connection counts. Matches the `ulimit -n` you would set in Docker.

## Graceful shutdown

Match `TimeoutStopSec` to your application's `shutdownTimeout`:

```php title="src/server.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(swoole_cpu_num())
    ->shutdownTimeout(Duration::seconds(12));
```

When systemd sends `SIGTERM`, Swoole stops accepting new connections and waits up to `shutdownTimeout` for in-flight requests to complete. After that, Swoole exits. systemd sends `SIGKILL` after `TimeoutStopSec` if the process has not exited.

## Reload and restart

```bash
# Enable and start the service
systemctl enable nexus-api
systemctl start nexus-api

# Reload after a code deploy (triggers graceful stop + restart)
systemctl restart nexus-api

# Watch logs
journalctl -u nexus-api -f
```

`systemctl restart` sends `SIGTERM`, waits for drain, and starts the new process. For zero-downtime deploys behind a load balancer, use a rolling restart strategy at the load balancer level rather than relying on systemd alone.

:::note Full systemd deployment guide
A comprehensive systemd guide — socket activation, journal integration, environment file management, and hardening directives (`CapabilityBoundingSet`, `ProtectSystem`, etc.) — is planned for a future documentation phase.
:::

## See also

- [Deployment](../deployment.md) — OPcache, health checks, graceful shutdown, and pre-flight checklist
- [Docker deployment](./docker.md) — container targets and sysctls
- [Kubernetes deployment](./kubernetes.md) — pod manifests, probes, and rolling updates
