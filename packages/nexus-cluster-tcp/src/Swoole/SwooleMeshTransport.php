<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Swoole;

use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use RuntimeException;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Socket;

use function extension_loaded;
use function sprintf;

/**
 * @psalm-api
 *
 * Swoole coroutine TCP mesh transport. Implements `MeshTransport` over real
 * network sockets using `Swoole\Coroutine\Server` (accept-loop) and
 * `Swoole\Coroutine\Client` (outbound connections).
 *
 * Both TLS and plaintext modes are supported. TLS is enabled by passing a
 * `TlsConfig` instance to the constructor; plaintext is the default.
 *
 * Security note: plaintext cluster connections must only be used on private,
 * trusted networks. Use TLS for anything exposed beyond a LAN.
 *
 * ext-swoole requirement: the constructor throws `RuntimeException` if
 * ext-swoole is not loaded. The rest of the package (loopback transport) works
 * without Swoole, so this guard ensures tests running on the plain PHP container
 * fail fast with a clear message rather than an undefined-class fatal error.
 *
 * Ephemeral ports: for testing, call `bindEphemeral()` before `serve()`.
 * `bindEphemeral()` creates the server socket synchronously (OS assigns a port),
 * returns the port, and stores the server for `serve()` to reuse. This avoids
 * the TOCTOU window of probe-and-rebind patterns.
 *
 * @example
 *   $transport = new SwooleMeshTransport($runtime);
 *   $port      = $transport->bindEphemeral(Host::of('127.0.0.1'));
 *   $bind      = new NodeEndpoint(Host::of('127.0.0.1'), Port::of($port));
 *   $transport->serve($bind, fn(PeerLink $link): void { ... });
 *   $link      = $transport->connect($bind);
 */
final class SwooleMeshTransport implements MeshTransport
{
    /** @var list<Server> */
    private array $servers = [];

    /** @var list<SwoolePeerLink> */
    private array $links = [];

    /**
     * Pre-bound server awaiting a serve() call. Set by bindEphemeral();
     * consumed and cleared by the next serve() call.
     */
    private ?Server $prebound = null;

    public function __construct(private readonly Runtime $runtime, private readonly ?TlsConfig $tls = null)
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'SwooleMeshTransport requires ext-swoole. '
                . 'Use LoopbackMeshTransport for Fiber/test environments.',
            );
        }
    }

    /**
     * Pre-bind a server socket to an OS-assigned ephemeral port on `$host`.
     * Returns the port number. The bound server is held internally and reused
     * by the next `serve()` call.
     *
     * This avoids the TOCTOU window inherent in probe-then-bind patterns and
     * is the recommended approach for integration tests.
     *
     * Must be called from inside `Co\run()` or after the SwooleRuntime has
     * started (i.e., within a coroutine context is not required — the
     * Coroutine\Server constructor binds synchronously via a raw socket).
     *
     * Not part of the `MeshTransport` interface; available for test code only.
     */
    public function bindEphemeral(Host $host): int
    {
        $ssl = $this->tls !== null;
        $server = new Server($host->value, 0, $ssl, false);

        if ($this->tls !== null) {
            $server->set($this->serverTlsSettings($this->tls));
        }

        $this->prebound = $server;

        return $server->port;
    }

    /**
     * Open a server that accepts incoming connections on `$bind`. For each
     * accepted connection the `$onAccept` callback is invoked asynchronously
     * inside a new coroutine spawned by the runtime.
     *
     * If `bindEphemeral()` was called before this method, the pre-bound server
     * socket is reused (the `$bind` parameter is only used for logging/tracking
     * in that case). Otherwise a new server socket is created and bound to the
     * endpoint in `$bind`.
     *
     * @param callable(PeerLink): void $onAccept
     */
    #[Override]
    public function serve(NodeEndpoint $bind, callable $onAccept): void
    {
        $ssl = $this->tls !== null;

        $server = $this->prebound ?? new Server($bind->host->value, $bind->port->value, $ssl, false);

        $this->prebound = null;

        if ($this->tls === null) {
            // No TLS — nothing to set
        } else {
            $server->set($this->serverTlsSettings($this->tls));
        }

        $this->servers[] = $server;

        $server->handle(function (Connection $conn) use ($onAccept): void {
            $socket = $conn->exportSocket();
            $link = new SwoolePeerLink($socket, $this->runtime, null);
            $this->links[] = $link;
            $onAccept($link);
        });

        $this->runtime->spawn(static function () use ($server): void {
            $server->start();
        });
    }

    /**
     * Connect to the peer at `$target` and return the client-side link.
     *
     * Blocks (suspends the calling coroutine) until the TCP handshake completes
     * or a timeout of 5 s elapses.
     *
     * @throws RuntimeException when the connection cannot be established.
     */
    #[Override]
    public function connect(NodeEndpoint $endpoint): PeerLink
    {
        $sockType = $this->tls !== null
            ? SWOOLE_SOCK_TCP | SWOOLE_SSL
            : SWOOLE_SOCK_TCP;

        $client = new Client($sockType);

        if ($this->tls !== null) {
            $client->set($this->clientTlsSettings($this->tls));
        }

        $connected = $client->connect($endpoint->host->value, $endpoint->port->value, 5.0);

        if (!$connected) {
            throw new RuntimeException(
                sprintf(
                    "SwooleMeshTransport: failed to connect to '%s' (error %d).",
                    $endpoint,
                    (int) $client->errCode,
                ),
            );
        }

        $socket = $client->exportSocket();

        if ($socket === false) {
            throw new RuntimeException(
                sprintf(
                    "SwooleMeshTransport: exportSocket() returned false for connection to '%s'.",
                    $endpoint,
                ),
            );
        }

        // Pass $client to SwoolePeerLink to keep it alive.
        // Swoole\Coroutine\Client::__destruct() closes the underlying socket;
        // without this reference the socket closes as soon as connect() returns.
        $link = new SwoolePeerLink($socket, $this->runtime, $endpoint, $client);
        $this->links[] = $link;

        return $link;
    }

    /**
     * Stop all listening servers and close all tracked peer links.
     *
     * Servers are shut down via `Server::shutdown()` (cancels the accept-loop
     * coroutine). Links are closed idempotently. Subsequent `serve()` and
     * `connect()` calls after `close()` are valid (a new server will be created).
     */
    #[Override]
    public function close(): void
    {
        foreach ($this->servers as $server) {
            $server->shutdown();
        }

        $this->servers = [];

        foreach ($this->links as $link) {
            $link->close();
        }

        $this->links = [];
    }

    /**
     * Build the Swoole server SSL settings array from a TlsConfig.
     * Keys are sorted alphabetically per the project's coding standard.
     *
     * @return array<string, mixed>
     */
    private function serverTlsSettings(TlsConfig $tls): array
    {
        $settings = [
            'ssl_cert_file' => $tls->certFile,
            'ssl_key_file' => $tls->keyFile,
            'ssl_verify_peer' => $tls->verifyPeer,
        ];

        if ($tls->caFile !== null) {
            $settings['ssl_cafile'] = $tls->caFile;
        }

        return $settings;
    }

    /**
     * Build the Swoole client SSL settings array from a TlsConfig.
     * Keys are sorted alphabetically per the project's coding standard.
     *
     * @return array<string, mixed>
     */
    private function clientTlsSettings(TlsConfig $tls): array
    {
        $settings = [
            'ssl_cert_file' => $tls->certFile,
            'ssl_key_file' => $tls->keyFile,
            'ssl_verify_peer' => $tls->verifyPeer,
        ];

        if ($tls->caFile !== null) {
            $settings['ssl_cafile'] = $tls->caFile;
        }

        return $settings;
    }
}
