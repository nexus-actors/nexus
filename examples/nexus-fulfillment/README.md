# nexus-fulfillment

Order-fulfillment reference application for the Nexus actor system —
event-sourced entity actors, a fulfillment saga with compensation,
WebSockets, and full OpenTelemetry observability. Built step by step;
the tutorial lives in `docs/tutorial/` (arrives with later milestones).

**Status: milestone 1 — foundation.** Docker toolchain, SharedKernel
value objects, Valinor wire serialization, Swoole HTTP server with
health probes, quality gates (PHPUnit, Psalm, Deptrac, CS).

## Run it

    make build      # PHP 8.5 ZTS + Swoole 6.2 image
    make install    # composer install inside the container
    make up         # server on http://localhost:9090
    curl localhost:9090/healthz
    curl localhost:9090/readyz

## Quality gates

    make ci         # phpunit + psalm + deptrac + php-cs-fixer + phpcs

This is a standalone Composer project inside the Nexus monorepo. To use it
as a starter outside the monorepo, copy the folder out and either keep a
sibling checkout of the Nexus `packages/` tree (adjusting the
`../../packages` mount in `compose.yaml`) or replace the `/nexus-packages`
autoload paths in `composer.json` with the published `nexus-actors/*`
Packagist packages.
