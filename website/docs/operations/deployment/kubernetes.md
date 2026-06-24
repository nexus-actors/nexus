---
title: Kubernetes deployment
sidebar_position: 3
related:
  - operations/deployment
  - operations/performance-tuning
  - operations/deployment/docker
  - operations/deployment/systemd
---

# Kubernetes deployment

Deploying Nexus on Kubernetes requires matching the pod termination grace period to `shutdownTimeout`, configuring liveness and readiness probes correctly, and raising kernel TCP limits via `sysctls`.

## Termination grace period and shutdown timeout

The most critical configuration: `terminationGracePeriodSeconds` must be longer than `shutdownTimeout`. A `preStop` sleep gives the load balancer time to drain connections before Nexus starts draining actors:

```yaml title="kubernetes/deployment.yaml"
spec:
  terminationGracePeriodSeconds: 20
  containers:
    - name: api
      image: myapp:latest
      lifecycle:
        preStop:
          exec:
            command: ["/bin/sh", "-c", "sleep 5"]
```

```php title="src/server.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(swoole_cpu_num())
    ->shutdownTimeout(Duration::seconds(12));
// 20s terminationGracePeriodSeconds - 5s preStop sleep - 3s safety buffer = 12s
```

## Health check probes

Wire the liveness and readiness endpoints from [Deployment](../deployment.md#health-checks) into the pod spec:

```yaml title="kubernetes/deployment.yaml"
containers:
  - name: api
    livenessProbe:
      httpGet:
        path: /health/live
        port: 8080
      initialDelaySeconds: 5
      periodSeconds: 10
      failureThreshold: 3
    readinessProbe:
      httpGet:
        path: /health/ready
        port: 8080
      initialDelaySeconds: 5
      periodSeconds: 5
      failureThreshold: 2
```

Kubernetes uses liveness to restart pods that are deadlocked but still running. It uses readiness to remove pods from the service endpoint list without restarting — the correct behavior during drain and during startup before actors are ready.

## Sysctls

Raise the kernel TCP limits to prevent 1-second tail latency under burst load. Set these at the pod level via `securityContext.sysctls`:

```yaml title="kubernetes/deployment.yaml"
spec:
  securityContext:
    sysctls:
      - name: net.core.somaxconn
        value: "65535"
      - name: net.ipv4.tcp_max_syn_backlog
        value: "65535"
      - name: net.ipv4.tcp_tw_reuse
        value: "1"
```

:::note Unsafe sysctls require node-level enablement
`net.ipv4.*` sysctls are classified as "unsafe" by Kubernetes and must be explicitly allowlisted on each node via the kubelet `--allowed-unsafe-sysctls` flag, or via a node configuration profile if you use a managed Kubernetes service.
:::

## Resource requests and limits

Size based on your actor graph and connection count, not the framework itself. The `ActorSystem` cold footprint is approximately 100KB:

```yaml title="kubernetes/deployment.yaml"
resources:
  requests:
    cpu: "1"
    memory: "256Mi"
  limits:
    cpu: "4"
    memory: "1Gi"
```

For worker pool deployments, set CPU requests to match `swoole_cpu_num()` — the pool creates one thread per core, and the CPU request ensures the pod is scheduled on a node with enough cores to run the pool without contention.

:::note Full Kubernetes deployment guide
A comprehensive Kubernetes guide — Helm chart, HPA configuration, PodDisruptionBudget, persistent volume claims for event stores, and multi-cluster routing — is planned for a future documentation phase.
:::

## See also

- [Deployment](../deployment.md) — OPcache, health checks, graceful shutdown, and pre-flight checklist
- [Performance tuning](../performance-tuning.md) — kernel TCP parameters explained
- [Docker deployment](./docker.md) — building the production image
- [systemd deployment](./systemd.md) — bare-metal and VM deployment
