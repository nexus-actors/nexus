---
title: Kernel Tuning (sysctls & ulimits)
related:
  - operations/performance-tuning
  - operations/deployment/docker
  - operations/deployment/kubernetes
---

# Kernel Tuning (sysctls & ulimits)

Under default Docker and Linux kernel settings, high-throughput Swoole servers hit TCP queue limits and file-descriptor limits before they hit PHP or Swoole limits. This page documents the four sysctls and one ulimit that matter most for Nexus deployments.

## The 1-second tail latency problem

On a default kernel configuration, the Swoole benchmarks show a `max` latency spike of ~1 second even when the `p50` is under 40 ms. The root cause is TCP SYN-queue overflow: when the kernel's per-listener accept queue fills, it drops the SYN packet and the client retries after the TCP retransmission timeout — exactly 1 second.

Raising the two queue depths and enabling `TIME_WAIT` socket reuse eliminates this class of tail latency.

## The four sysctls

| Sysctl | Default | Recommended | Effect |
|---|---|---|---|
| `net.core.somaxconn` | 128 | 65535 | Maximum depth of the per-listener accept queue |
| `net.ipv4.tcp_max_syn_backlog` | 128 | 65535 | Maximum depth of the per-listener SYN queue |
| `net.ipv4.tcp_tw_reuse` | 0 | 1 | Allow reuse of `TIME_WAIT` sockets for new outbound connections |
| `net.ipv4.ip_local_port_range` | `32768 60999` | `1024 65535` | Expands the ephemeral port range for outbound connections |

`somaxconn` and `tcp_max_syn_backlog` must both be raised — the kernel uses the lower of the two as the effective queue depth. Raising only one has no effect.

`tcp_tw_reuse` applies to outbound connections (e.g. Nexus actors connecting to databases). Do not confuse it with `tcp_tw_recycle`, which was removed in Linux 4.12 and breaks NAT.

## Docker Compose

```yaml title="compose.yaml"
services:
  php-swoole:
    build:
      context: .
      dockerfile: docker/Dockerfile
      target: php-swoole
    ports:
      - "8080:8080"
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

The `nofile` ulimit sets the open-file-descriptor limit for the container. Each Swoole connection consumes one file descriptor. The default limit of 1024 caps concurrency at roughly 900 simultaneous connections (system FDs take the rest).

## Kubernetes

Apply sysctls via pod security context. Most cloud providers require you to enable unsafe sysctls in the kubelet configuration first:

```yaml title="k8s/deployment.yaml"
spec:
  template:
    spec:
      securityContext:
        sysctls:
          - name: net.core.somaxconn
            value: "65535"
          - name: net.ipv4.tcp_max_syn_backlog
            value: "65535"
          - name: net.ipv4.tcp_tw_reuse
            value: "1"
      containers:
        - name: app
          resources: {}
          securityContext:
            capabilities:
              add: ["NET_ADMIN"]
```

File-descriptor limits in Kubernetes come from the `resources` section and the node's `fs.file-max` sysctl. Setting `nofile` via `ulimits` is a Docker-specific key; in Kubernetes use a `LimitRange` or set `--max-open-files` on the kubelet.

## systemd

For bare-metal or VM deployments managed by systemd, add the following to your service unit:

```ini title="/etc/systemd/system/nexus-app.service"
[Service]
LimitNOFILE=65535

[Unit]
# Sysctls are set at boot via /etc/sysctl.d/, not in the unit file.
```

```ini title="/etc/sysctl.d/99-nexus.conf"
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.ip_local_port_range = 1024 65535
```

Apply without reboot: `sudo sysctl --system`.

## Measured impact

These settings were benchmarked against the Nexus HTTP performance suite. On a default Docker configuration (no sysctls), a 15-second load test produced 14 timeout events with a `max` of 1.40 s. With all four sysctls applied, timeouts dropped to 1 and `max` fell to 1.00 s. See [Performance tuning](./performance-tuning.md) for the full results table.

## See also

- [Performance tuning](./performance-tuning.md) — full benchmark methodology and measured impact
- [Docker deployment](./deployment/docker.md)
- [Kubernetes deployment](./deployment/kubernetes.md)
