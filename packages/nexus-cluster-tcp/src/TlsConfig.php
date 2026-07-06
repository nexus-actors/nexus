<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

/**
 * @psalm-api
 *
 * Optional TLS configuration for Swoole TCP cluster connections.
 * When set on ClusterTopology, the cluster server and outbound peer connections
 * enable SSL using the Swoole socket SSL options.
 *
 * Security note: plaintext cluster ports must never be exposed to untrusted
 * networks. Use TLS + network policy for anything beyond a private LAN.
 *
 * @example
 * $tls = new TlsConfig(
 *     certFile: '/certs/node.crt',
 *     keyFile:  '/certs/node.key',
 *     caFile:   '/certs/ca.crt',
 *     verifyPeer: true,
 * );
 */
final readonly class TlsConfig
{
    public function __construct(
        public string $certFile,
        public string $keyFile,
        public ?string $caFile = null,
        public bool $verifyPeer = true,
    ) {}
}
