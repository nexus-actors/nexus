# nexus-http-server-swoole + threads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two new packages — `nexus-http-server-swoole` (worker mode + PSR-7↔Swoole bridge + WebSocket primitives) and `nexus-http-server-swoole-threads` (thread mode + cross-thread WebSocket broadcast) — that implement `HttpServerAdapter` from `nexus-http` and serve both HTTP and WebSocket traffic over Swoole.

**Architecture:** Each runner builds a `Swoole\WebSocket\Server` (extends `Swoole\Http\Server`) and registers events for `Request`, `Open`, `Message`, `Close`. The PSR-7 bridge translates Swoole request/response objects. WebSocket routes match via a FastRoute dispatcher on the upgrade request's path. Two WebSocket models per route: per-connection handler factory OR channel-keyed actor (path param drives actor identity). Worker mode = `WorkerLocal` channel actors; thread mode = pool-singleton via `WorkerNode` ring + cross-thread broadcast via `WorkerTransport`.

**Tech Stack:** PHP 8.5+, ext-swoole ^6.0, `nyholm/psr7` ^1.8, `nikic/fast-route` ^1.3 (for WebSocket router), `nexus-actors/http` (consumer), `nexus-actors/runtime-swoole`, `nexus-actors/worker-pool-swoole` (threads package only). Tests via PHPUnit 13 in the `php-swoole` Docker container.

**Spec:** `docs/superpowers/specs/2026-06-11-nexus-http-server-swoole-design.md` (commit `eb7a12da`).

**Key real-world discrepancies (already accounted for):**
- `WebSocketContext::id()` returns int (fd), not a string identifier.
- `ChannelMessageReceived` arrives at the channel actor; the actor decides broadcast policy.
- `ThreadAwareWebSocketContext` is the only file that differs between modes; everything else is shared.

---

## Phase 0: Bootstrap nexus-http-server-swoole package

**Outcome:** Empty `packages/nexus-http-server-swoole` package present, autoloaded, with composer install passing and an empty unit-swoole testsuite that runs green.

**Files:**
- Create: `packages/nexus-http-server-swoole/composer.json`
- Create: `packages/nexus-http-server-swoole/README.md`
- Create: `packages/nexus-http-server-swoole/src/.gitkeep`
- Create: `packages/nexus-http-server-swoole/tests/Unit/.gitkeep`
- Modify: `composer.json` (root) — add `Monadial\Nexus\Http\Server\Swoole\` autoload entries
- Modify: `phpunit.xml` — add new `unit-http-server-swoole` testsuite
- Modify: `deptrac.yaml` — add `HttpServerSwoole` layer

- [ ] **Step 1:** Create directories

```bash
mkdir -p packages/nexus-http-server-swoole/src \
         packages/nexus-http-server-swoole/tests/Unit
touch packages/nexus-http-server-swoole/src/.gitkeep \
      packages/nexus-http-server-swoole/tests/Unit/.gitkeep
```

- [ ] **Step 2:** Write `packages/nexus-http-server-swoole/composer.json`

```json
{
    "name": "nexus-actors/http-server-swoole",
    "description": "Nexus HTTP server — Swoole worker-mode adapter with WebSocket support.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/core": "dev-main",
        "nexus-actors/http": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nexus-actors/runtime-swoole": "dev-main",
        "nikic/fast-route": "^1.3",
        "nyholm/psr7": "^1.8",
        "psr/http-message": "^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Server\\Swoole\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Server\\Swoole\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3:** Write minimal README

`packages/nexus-http-server-swoole/README.md`:

```markdown
# nexus-http-server-swoole

Swoole worker-mode HTTP server with WebSocket support, for `nexus-http`.

> See `docs/superpowers/specs/2026-06-11-nexus-http-server-swoole-design.md` for the design.

## Install

```bash
composer require nexus-actors/http-server-swoole
```

## Status

In active development.
```

- [ ] **Step 4:** Add root composer.json autoload entries

Edit root `composer.json`. Add to `autoload.psr-4`:

```json
"Monadial\\Nexus\\Http\\Server\\Swoole\\": "packages/nexus-http-server-swoole/src/",
```

Add to `autoload-dev.psr-4`:

```json
"Monadial\\Nexus\\Http\\Server\\Swoole\\Tests\\": "packages/nexus-http-server-swoole/tests/",
```

- [ ] **Step 5:** Add new testsuite to `phpunit.xml`

Append inside the `<testsuite name="unit-swoole">` block:

```xml
            <directory>packages/nexus-http-server-swoole/tests/Unit</directory>
```

- [ ] **Step 6:** Add deptrac layer

Edit `deptrac.yaml` — add `HttpServerSwoole` layer after `Http`:

```yaml
    - name: HttpServerSwoole
      collectors:
        - type: directory
          value: packages/nexus-http-server-swoole/src/.*
```

Add to ruleset:

```yaml
  HttpServerSwoole:
    - Core
    - Runtime
    - Http
    - RuntimeSwoole
```

- [ ] **Step 7:** composer install + verify

```bash
make install
```
Expected: clean install.

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit-swoole 2>&1 | tail -5
```
Expected: all existing swoole unit tests pass; nexus-http-server-swoole contributes 0 tests.

- [ ] **Step 8:** Add coverage source dir to phpunit.xml

Append to the `<source><include>` block (alongside other `*/src` entries):

```xml
            <directory>packages/nexus-http-server-swoole/src</directory>
```

- [ ] **Step 9:** Commit

```bash
git add packages/nexus-http-server-swoole composer.json phpunit.xml deptrac.yaml
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): bootstrap package skeleton

Empty package wired into the monorepo autoload, unit-swoole testsuite,
and deptrac. Dependencies: ext-swoole, nexus-http, nexus-runtime-swoole,
nyholm/psr7, nikic/fast-route."
```

---

## Phase 1: PSR-7 ↔ Swoole bridge

**Outcome:** `SwooleRequestTranslator`, `SwooleResponseWriter`, `SwooleStreamingDetector` implemented with full unit-test coverage. No Swoole server boot required for these tests — anonymous-class mocks of `Swoole\Http\Request`/`Response`.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/Bridge/SwooleStreamingDetector.php`
- Create: `packages/nexus-http-server-swoole/src/Bridge/SwooleRequestTranslator.php`
- Create: `packages/nexus-http-server-swoole/src/Bridge/SwooleResponseWriter.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleStreamingDetectorTest.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleRequestTranslatorTest.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleResponseWriterTest.php`

- [ ] **Step 1:** Failing test for `SwooleStreamingDetector`

Create `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleStreamingDetectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Response\IteratorStream;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleStreamingDetector;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleStreamingDetector::class)]
final class SwooleStreamingDetectorTest extends TestCase
{
    #[Test]
    public function iterator_stream_with_unknown_size_is_streaming(): void
    {
        $iter = (static function () { yield 'a'; })();
        self::assertTrue(SwooleStreamingDetector::isStreaming(new IteratorStream($iter)));
    }

    #[Test]
    public function fixed_string_stream_is_not_streaming(): void
    {
        self::assertFalse(SwooleStreamingDetector::isStreaming(Stream::create('hello')));
    }

    #[Test]
    public function empty_stream_is_not_streaming(): void
    {
        self::assertFalse(SwooleStreamingDetector::isStreaming(Stream::create('')));
    }
}
```

- [ ] **Step 2:** Run — expect FAIL (class missing)

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleStreamingDetectorTest.php 2>&1 | tail -5
```

- [ ] **Step 3:** Implement `SwooleStreamingDetector`

Create `packages/nexus-http-server-swoole/src/Bridge/SwooleStreamingDetector.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Psr\Http\Message\StreamInterface;

/**
 * @psalm-api
 *
 * Decides whether a PSR-7 response body should be streamed (per-chunk writes)
 * vs sent in a single end() call.
 *
 * Heuristic: getSize() === null means the stream's total length isn't known
 * up front — that's the signal a producer is yielding chunks lazily
 * (IteratorStream, generator-backed bodies). Concrete file/string streams
 * always report a size.
 */
final class SwooleStreamingDetector
{
    public static function isStreaming(StreamInterface $body): bool
    {
        return $body->getSize() === null;
    }
}
```

- [ ] **Step 4:** Re-run — expect 3/3 PASS

- [ ] **Step 5:** Failing test for `SwooleRequestTranslator`

Create `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleRequestTranslatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;

#[CoversClass(SwooleRequestTranslator::class)]
final class SwooleRequestTranslatorTest extends TestCase
{
    #[Test]
    public function maps_method_uri_and_query(): void
    {
        $req = new class extends SwooleRequest {
            public array $server = [
                'request_method' => 'GET',
                'request_uri'    => '/users/42',
                'query_string'   => 'q=phpunit&n=10',
                'server_protocol' => 'HTTP/1.1',
            ];
            public array $header = ['host' => 'localhost:8080'];
            public array $get    = ['q' => 'phpunit', 'n' => '10'];
            public function rawContent(): string|false { return ''; }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame('GET', $psr7->getMethod());
        self::assertSame('/users/42', $psr7->getUri()->getPath());
        self::assertSame('q=phpunit&n=10', $psr7->getUri()->getQuery());
        self::assertSame(['q' => 'phpunit', 'n' => '10'], $psr7->getQueryParams());
        self::assertSame('localhost:8080', $psr7->getHeaderLine('host'));
    }

    #[Test]
    public function maps_post_body_as_parsed_body(): void
    {
        $req = new class extends SwooleRequest {
            public array $server = ['request_method' => 'POST', 'request_uri' => '/u'];
            public array $header = [];
            public array $post = ['name' => 'tomas'];
            public function rawContent(): string|false { return 'name=tomas'; }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame(['name' => 'tomas'], $psr7->getParsedBody());
        self::assertSame('name=tomas', (string) $psr7->getBody());
    }

    #[Test]
    public function maps_cookies(): void
    {
        $req = new class extends SwooleRequest {
            public array $server = ['request_method' => 'GET', 'request_uri' => '/'];
            public array $header = [];
            public array $cookie = ['session' => 'abc123'];
            public function rawContent(): string|false { return ''; }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame(['session' => 'abc123'], $psr7->getCookieParams());
    }
}
```

- [ ] **Step 6:** Run — expect FAIL

- [ ] **Step 7:** Implement `SwooleRequestTranslator`

Create `packages/nexus-http-server-swoole/src/Bridge/SwooleRequestTranslator.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Swoole\Http\Request as SwooleRequest;

/**
 * @psalm-api
 *
 * Maps a Swoole\Http\Request into a PSR-7 ServerRequest backed by nyholm/psr7.
 */
final class SwooleRequestTranslator
{
    public static function toPsr7(SwooleRequest $req): ServerRequestInterface
    {
        $server = $req->server ?? [];
        $method = strtoupper((string) ($server['request_method'] ?? 'GET'));
        $path   = (string) ($server['request_uri'] ?? '/');
        $query  = (string) ($server['query_string'] ?? '');
        $version = self::extractProtocolVersion($server);

        $uri = $path . ($query !== '' ? '?' . $query : '');
        $rawBody = $req->rawContent();
        $body    = is_string($rawBody) ? $rawBody : '';

        $request = new ServerRequest(
            $method,
            $uri,
            $req->header ?? [],
            $body,
            $version,
            $server,
        );

        if (!empty($req->cookie)) {
            $request = $request->withCookieParams($req->cookie);
        }
        if (!empty($req->get)) {
            $request = $request->withQueryParams($req->get);
        }
        if (!empty($req->post)) {
            $request = $request->withParsedBody($req->post);
        }
        if (!empty($req->files)) {
            $request = $request->withUploadedFiles(self::buildUploadedFiles($req->files));
        }

        return $request;
    }

    /** @param array<string, mixed> $server */
    private static function extractProtocolVersion(array $server): string
    {
        $protocol = (string) ($server['server_protocol'] ?? 'HTTP/1.1');
        if (str_starts_with($protocol, 'HTTP/')) {
            return substr($protocol, 5);
        }

        return '1.1';
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface>
     */
    private static function buildUploadedFiles(array $files): array
    {
        $out = [];
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }
            $out[$name] = new UploadedFile(
                (string) ($file['tmp_name'] ?? ''),
                (int) ($file['size'] ?? 0),
                (int) ($file['error'] ?? UPLOAD_ERR_OK),
                isset($file['name']) ? (string) $file['name'] : null,
                isset($file['type']) ? (string) $file['type'] : null,
            );
        }

        return $out;
    }
}
```

- [ ] **Step 8:** Re-run — expect 3/3 PASS

- [ ] **Step 9:** Failing test for `SwooleResponseWriter`

Create `packages/nexus-http-server-swoole/tests/Unit/Bridge/SwooleResponseWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Response\IteratorStream;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

#[CoversClass(SwooleResponseWriter::class)]
final class SwooleResponseWriterTest extends TestCase
{
    private function fakeResponse(): SwooleResponse
    {
        return new class extends SwooleResponse {
            public int $statusCode = 0;
            public array $headers = [];
            public array $chunks = [];
            public ?string $endBody = null;
            public bool $ended = false;

            public function status(int|string $http_code, string $reason = ''): bool
            {
                $this->statusCode = (int) $http_code;
                return true;
            }

            public function header(string $key, string|array $value, bool $format = true): bool
            {
                $this->headers[$key][] = is_array($value) ? implode(',', $value) : $value;
                return true;
            }

            public function write(mixed $data): bool
            {
                $this->chunks[] = (string) $data;
                return true;
            }

            public function end(mixed $content = ''): bool
            {
                if ((string) $content !== '') {
                    $this->endBody = (string) $content;
                }
                $this->ended = true;
                return true;
            }
        };
    }

    #[Test]
    public function writes_status_headers_and_body(): void
    {
        $fake = $this->fakeResponse();
        $psr7 = (new Psr7Response(200, ['Content-Type' => 'text/plain'], 'hello'));

        SwooleResponseWriter::write($psr7, $fake);

        self::assertSame(200, $fake->statusCode);
        self::assertSame('text/plain', $fake->headers['Content-Type'][0]);
        self::assertTrue($fake->ended);
        self::assertSame('hello', $fake->endBody);
        self::assertSame([], $fake->chunks);
    }

    #[Test]
    public function 204_no_content_omits_body(): void
    {
        $fake = $this->fakeResponse();
        SwooleResponseWriter::write(Response::noContent(), $fake);

        self::assertSame(204, $fake->statusCode);
        self::assertTrue($fake->ended);
        self::assertNull($fake->endBody);
    }

    #[Test]
    public function streaming_body_is_written_per_chunk(): void
    {
        $iter = (static function () {
            yield 'one';
            yield 'two';
            yield 'three';
        })();

        $stream = new IteratorStream($iter);
        $psr7 = (new Psr7Response(200))->withBody($stream);

        $fake = $this->fakeResponse();
        SwooleResponseWriter::write($psr7, $fake);

        self::assertSame(['one', 'two', 'three'], $fake->chunks);
        self::assertTrue($fake->ended);
        self::assertNull($fake->endBody);
    }
}
```

- [ ] **Step 10:** Run — expect FAIL

- [ ] **Step 11:** Implement `SwooleResponseWriter`

Create `packages/nexus-http-server-swoole/src/Bridge/SwooleResponseWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Response as SwooleResponse;

/**
 * @psalm-api
 *
 * Writes a PSR-7 ResponseInterface to a Swoole\Http\Response. Streams bodies
 * with unknown size per chunk via $swoole->write(); buffered bodies use a
 * single end().
 *
 * 204/304 responses always go out with bare end() (no body, per spec).
 */
final class SwooleResponseWriter
{
    public static function write(ResponseInterface $psr7, SwooleResponse $swoole): void
    {
        $swoole->status($psr7->getStatusCode(), $psr7->getReasonPhrase());

        foreach ($psr7->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swoole->header($name, $value);
            }
        }

        $statusCode = $psr7->getStatusCode();
        if ($statusCode === 204 || $statusCode === 304) {
            $swoole->end();

            return;
        }

        $body = $psr7->getBody();

        if (SwooleStreamingDetector::isStreaming($body)) {
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                $swoole->write($chunk);
            }
            $swoole->end();

            return;
        }

        $swoole->end((string) $body);
    }
}
```

- [ ] **Step 12:** Re-run — expect 3/3 PASS

- [ ] **Step 13:** Lint

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/psalm 2>&1 | tail -5
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpcs 2>&1 | tail -5
```

Expected: clean.

- [ ] **Step 14:** Commit

```bash
git add packages/nexus-http-server-swoole/src/Bridge \
        packages/nexus-http-server-swoole/tests/Unit/Bridge
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): add PSR-7 <-> Swoole bridge

SwooleRequestTranslator maps Swoole\Http\Request to nyholm/psr7
ServerRequest. SwooleResponseWriter writes PSR-7 responses to
Swoole\Http\Response, streaming per chunk for IteratorStream-backed
bodies. SwooleStreamingDetector keys off StreamInterface::getSize()
returning null."
```

---

## Phase 2: SwooleHttpServerAdapter + contract test

**Outcome:** `SwooleHttpServerAdapter` (implements `HttpServerAdapter` from nexus-http) + a concrete `SwooleHttpServerAdapterContractTest` extending the abstract base from `nexus-http/tests/Contract`. The contract test is integration-tier (real Swoole server in php-swoole container).

**Files:**
- Create: `packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php`
- Create: `tests/Integration/HttpSwoole/SwooleHttpServerAdapterContractTest.php`
- Modify: `phpunit.xml` — add `integration-http-swoole` testsuite
- Modify: `Makefile` — add `test-http-swoole` target

- [ ] **Step 1:** Implement `SwooleHttpServerAdapter`

Create `packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * @psalm-api
 *
 * Thin HttpServerAdapter that wires a Swoole\Http\Server to a PSR-15
 * RequestHandlerInterface. Use SwooleWorkerHttpServer::run() for the
 * production entry point; this class exists primarily for the
 * HttpServerAdapterContractTest from nexus-http.
 */
final class SwooleHttpServerAdapter implements HttpServerAdapter
{
    private bool $running = false;

    public function __construct(private readonly Server $server) {}

    #[Override]
    public function serve(RequestHandlerInterface $app): void
    {
        $this->server->on('Request', static function (Request $req, Response $res) use ($app): void {
            $psr7 = SwooleRequestTranslator::toPsr7($req);
            SwooleResponseWriter::write($app->handle($psr7), $res);
        });

        $this->running = true;
        $this->server->start();
    }

    #[Override]
    public function shutdown(Duration $timeout): void
    {
        if (!$this->running) {
            return;
        }
        $this->server->shutdown();
        $this->running = false;
    }
}
```

- [ ] **Step 2:** Add `integration-http-swoole` testsuite to `phpunit.xml`

Append next to `integration-swoole`:

```xml
        <testsuite name="integration-http-swoole">
            <directory>tests/Integration/HttpSwoole</directory>
        </testsuite>
```

- [ ] **Step 3:** Add make target

Append to `Makefile`:

```makefile
test-http-swoole: ## HTTP Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole
```

- [ ] **Step 4:** Create the contract test

Create `tests/Integration/HttpSwoole/SwooleHttpServerAdapterContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleHttpServerAdapter;
use Monadial\Nexus\Http\Tests\Contract\HttpServerAdapterContractTest;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Server;

final class SwooleHttpServerAdapterContractTest extends HttpServerAdapterContractTest
{
    private int $port = 0;
    private ?Server $server = null;

    protected function createAdapter(): HttpServerAdapter
    {
        $this->port = $this->findFreePort();
        $this->server = new Server('127.0.0.1', $this->port);
        $this->server->set([
            'worker_num'   => 1,
            'log_level'    => SWOOLE_LOG_NOTICE,
            'log_file'     => '/tmp/nexus-http-swoole-contract.log',
        ]);

        return new SwooleHttpServerAdapter($this->server);
    }

    protected function bindAddress(): array
    {
        return ['127.0.0.1', $this->port];
    }

    protected function sendGet(string $path): string
    {
        $client = new Client('127.0.0.1', $this->port);
        $client->get($path);
        $body = $client->body;
        $client->close();

        return $body;
    }

    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $_, $port);
        socket_close($sock);

        return (int) $port;
    }
}
```

- [ ] **Step 5:** Run

```bash
make test-http-swoole 2>&1 | tail -10
```

Expected: 1 test PASS (the contract test's `adapter_handles_request_and_writes_response`).

- [ ] **Step 6:** Commit

```bash
git add packages/nexus-http-server-swoole/src/Server/SwooleHttpServerAdapter.php \
        tests/Integration/HttpSwoole \
        phpunit.xml Makefile
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): implement SwooleHttpServerAdapter + contract test

Wires a Swoole\Http\Server to PSR-15 RequestHandlerInterface via the
bridge. Concrete HttpServerAdapterContractTest binds to an ephemeral
loopback port, runs a real Swoole server in the php-swoole container,
and round-trips a request via Swoole\Coroutine\Http\Client."
```

---

## Phase 3: SwooleWorkerConfig

**Outcome:** Immutable value object capturing all worker-mode tunables. Pure value-object tests, no Swoole runtime needed.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerConfig.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/Server/SwooleWorkerConfigTest.php`

- [ ] **Step 1:** Failing test

Create `packages/nexus-http-server-swoole/tests/Unit/Server/SwooleWorkerConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Server;

use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SwooleWorkerConfig::class)]
final class SwooleWorkerConfigTest extends TestCase
{
    #[Test]
    public function bind_captures_host_and_port(): void
    {
        $cfg = SwooleWorkerConfig::bind('0.0.0.0', 9000);
        self::assertSame('0.0.0.0', $cfg->host);
        self::assertSame(9000, $cfg->port);
    }

    #[Test]
    public function workers_returns_new_instance(): void
    {
        $a = SwooleWorkerConfig::bind('0.0.0.0', 8080);
        $b = $a->workers(8);

        self::assertNotSame($a, $b);
        self::assertSame(1, $a->workers);
        self::assertSame(8, $b->workers);
    }

    #[Test]
    public function defaults_are_sensible(): void
    {
        $cfg = SwooleWorkerConfig::bind('0.0.0.0', 8080);

        self::assertSame(1, $cfg->workers);
        self::assertSame(0, $cfg->reactorThreads);   // 0 = swoole default
        self::assertSame(0, $cfg->maxRequest);        // unlimited
        self::assertTrue($cfg->installSignalHandlers);
        self::assertInstanceOf(NullLogger::class, $cfg->logger);
    }
}
```

- [ ] **Step 2:** Run — FAIL

- [ ] **Step 3:** Implement `SwooleWorkerConfig`

Create `packages/nexus-http-server-swoole/src/Server/SwooleWorkerConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Immutable configuration for SwooleWorkerHttpServer::run().
 * Constructed via the static bind() entry; further tunables return
 * new instances.
 */
final readonly class SwooleWorkerConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $workers,
        public int $reactorThreads,
        public int $maxRequest,
        public int $maxConn,
        public int $dispatchMode,
        public Duration $shutdownTimeout,
        public bool $installSignalHandlers,
        public LoggerInterface $logger,
        public string $logFile,
    ) {}

    public static function bind(string $host, int $port = 8080): self
    {
        return new self(
            host: $host,
            port: $port,
            workers: 1,
            reactorThreads: 0,
            maxRequest: 0,
            maxConn: 0,
            dispatchMode: 2,
            shutdownTimeout: Duration::seconds(10),
            installSignalHandlers: true,
            logger: new NullLogger(),
            logFile: '',
        );
    }

    public function workers(int $n): self
    {
        return new self(
            $this->host, $this->port, $n, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function reactorThreads(int $n): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $n,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function maxRequest(int $n): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $n, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function maxConn(int $n): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $n, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function dispatchMode(int $mode): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $mode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function shutdownTimeout(Duration $d): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $d, $this->installSignalHandlers,
            $this->logger, $this->logFile,
        );
    }

    public function installSignalHandlers(bool $b): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $b,
            $this->logger, $this->logFile,
        );
    }

    public function logger(LoggerInterface $log): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $log, $this->logFile,
        );
    }

    public function logFile(string $path): self
    {
        return new self(
            $this->host, $this->port, $this->workers, $this->reactorThreads,
            $this->maxRequest, $this->maxConn, $this->dispatchMode,
            $this->shutdownTimeout, $this->installSignalHandlers,
            $this->logger, $path,
        );
    }
}
```

- [ ] **Step 4:** Re-run — 3/3 PASS

- [ ] **Step 5:** Lint + commit

```bash
make psalm && make phpcs
git add packages/nexus-http-server-swoole/src/Server/SwooleWorkerConfig.php \
        packages/nexus-http-server-swoole/tests/Unit/Server/SwooleWorkerConfigTest.php
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): add SwooleWorkerConfig

Immutable value object capturing worker-mode tunables (bind address,
worker/reactor counts, max request, shutdown timeout, signal handling,
logger). Mutators return new instances per project convention."
```

---

## Phase 4: SwooleWorkerHttpServer (HTTP only, no WebSocket yet)

**Outcome:** Worker-mode runner that boots a Swoole HTTP server, runs the factory inside each worker, and serves HTTP requests. WebSocket support comes in Phase 9. No integration test yet (Phase 5).

**Files:**
- Create: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php`

- [ ] **Step 1:** Implement (no failing test first — this is integration-tier code; Phase 5 covers it end-to-end)

Create `packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Http\Server;
use Throwable;

/**
 * @psalm-api
 *
 * Worker-mode HTTP runner. Boots ext-swoole's master + N worker processes.
 * Each worker runs the factory to build a per-worker CompiledHttpApp at
 * WorkerStart, then serves Request events.
 *
 * Pool-singleton actors are NOT available in worker mode (no shared ring
 * across processes). Use thread mode for pool-singletons.
 */
final class SwooleWorkerHttpServer
{
    /** @var array<int, CompiledHttpApp> */
    private static array $appsByServerId = [];

    /** @var array<int, ActorSystem> */
    private static array $systemsByServerId = [];

    /** @var array<int, array{count:int, since:float}> */
    private static array $failureCounters = [];

    /** @param Closure(ActorSystem): CompiledHttpApp $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $server = new Server($config->host, $config->port);

        $settings = [
            'worker_num'   => $config->workers,
            'reactor_num'  => $config->reactorThreads,
            'max_request'  => $config->maxRequest,
            'max_conn'     => $config->maxConn,
            'dispatch_mode' => $config->dispatchMode,
        ];
        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }
        $server->set($settings);

        $server->on('WorkerStart', static function (Server $s, int $workerId) use ($factory, $config): void {
            try {
                $system = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                $app    = $factory($system);

                $serverId = spl_object_id($s);
                self::$appsByServerId[$serverId] = $app;
                self::$systemsByServerId[$serverId] = $system;
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', [
                    'exception' => $e,
                    'workerId' => $workerId,
                ]);
                self::recordFailureAndMaybeShutdown($s, $config);
            }
        });

        $server->on('Request', static function ($req, $res) use ($config): void {
            try {
                $serverId = spl_object_id($req->server ?? new \stdClass());
                $app = self::$appsByServerId[$serverId] ?? null;
                if ($app === null) {
                    // server isn't tracked; fall back to first registered (single-server typical)
                    $app = reset(self::$appsByServerId);
                }
                if ($app === false) {
                    $res->status(503);
                    $res->end('Service not ready');

                    return;
                }
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request handling failed', ['exception' => $e]);
                if (!$res->isWritable()) {
                    return;
                }
                $res->status(500);
                $res->end('Internal Server Error');
            }
        });

        $server->on('WorkerStop', static function (Server $s, int $workerId) use ($config): void {
            $serverId = spl_object_id($s);
            $system   = self::$systemsByServerId[$serverId] ?? null;
            if ($system !== null) {
                try {
                    $system->shutdown($config->shutdownTimeout);
                } catch (Throwable $e) {
                    $config->logger->error('System shutdown failed in WorkerStop', [
                        'exception' => $e,
                        'workerId' => $workerId,
                    ]);
                }
            }
            unset(self::$appsByServerId[$serverId], self::$systemsByServerId[$serverId]);
        });

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }

    private static function recordFailureAndMaybeShutdown(Server $server, SwooleWorkerConfig $config): void
    {
        $serverId = spl_object_id($server);
        $now      = microtime(true);
        $bucket   = self::$failureCounters[$serverId] ?? ['count' => 0, 'since' => $now];

        if ($now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }
        self::$failureCounters[$serverId] = $bucket;

        if ($bucket['count'] >= 3) {
            $config->logger->error(
                'HTTP factory failed during worker boot 3 times in 5s — shutting down master.',
            );
            $server->shutdown();
        }
    }
}
```

- [ ] **Step 2:** Lint passes (no test yet — Phase 5)

```bash
make psalm && make phpcs
```

- [ ] **Step 3:** Commit

```bash
git add packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): add SwooleWorkerHttpServer

Static run(config, factory) entry. Per WorkerStart event, builds an
ActorSystem with SwooleRuntime and invokes the factory; caches the
CompiledHttpApp keyed by spl_object_id. Request events drive the
PSR-7 pipeline via the bridge. WorkerStop calls system->shutdown()
with configured timeout. Includes restart-loop protection (3
failures in 5s -> master shutdown)."
```

---

## Phase 5: Worker-mode HTTP integration test

**Outcome:** A real Swoole worker-mode server boots in the php-swoole container, serves a /hello route, asserts response correctness. Validates the end-to-end Phase 0-4 work.

**Files:**
- Create: `tests/Integration/HttpSwoole/WorkerModeHttpIntegrationTest.php`

- [ ] **Step 1:** Write test

Create `tests/Integration/HttpSwoole/WorkerModeHttpIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine\Http\Client;

#[CoversNothing]
final class WorkerModeHttpIntegrationTest extends TestCase
{
    #[Test]
    public function serves_compiled_http_app(): void
    {
        $port = $this->findFreePort();

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child — boot the server
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false),
                factory: static function (ActorSystem $system): CompiledHttpApp {
                    return HttpApp::create($system)
                        ->get('/hello', static fn(): ResponseInterface => Response::ok())
                        ->compile();
                },
            );
            exit(0);
        }

        usleep(500_000); // 500ms for boot

        try {
            \Co\run(function () use ($port): void {
                $client = new Client('127.0.0.1', $port);
                $client->get('/hello');
                self::assertSame(200, $client->statusCode);
                $client->close();
            });
        } finally {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
    }

    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $_, $port);
        socket_close($sock);

        return (int) $port;
    }
}
```

- [ ] **Step 2:** Run

```bash
make test-http-swoole 2>&1 | tail -10
```

Expected: 2 tests PASS (the new + the contract test from Phase 2).

- [ ] **Step 3:** Commit

```bash
git add tests/Integration/HttpSwoole/WorkerModeHttpIntegrationTest.php
git -c commit.gpgsign=false commit -m "test(http-server-swoole): worker-mode HTTP round-trip integration

Forks a child process running SwooleWorkerHttpServer with a 1-worker
config, then drives a request via Swoole\Coroutine\Http\Client.
Asserts status 200 from /hello, kills the child via SIGTERM."
```

---

## Phase 6: ShutdownSignalHandler

**Outcome:** Signal handler class installs SIGTERM/SIGINT → `$server->shutdown()`. Tested with a fake server.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/Signal/ShutdownSignalHandler.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/Signal/ShutdownSignalHandlerTest.php`

- [ ] **Step 1:** Failing test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Signal;

use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ShutdownSignalHandler::class)]
final class ShutdownSignalHandlerTest extends TestCase
{
    #[Test]
    public function install_returns_void_and_does_not_throw(): void
    {
        // The class registers signal handlers via Swoole\Process::signal,
        // which can only be meaningfully tested in an integration setting.
        // This unit test just verifies the call surface compiles.
        $server = new class extends \Swoole\Http\Server {
            public bool $shutdown = false;

            public function __construct() { /* skip parent ctor for test */ }

            public function shutdown(): bool
            {
                $this->shutdown = true;
                return true;
            }
        };

        // Don't actually install if not in swoole runtime context
        if (!function_exists('Swoole\\Process\\signal')) {
            self::markTestSkipped('Swoole\Process not available');
        }

        ShutdownSignalHandler::install($server, new NullLogger());
        self::assertFalse($server->shutdown);
    }
}
```

- [ ] **Step 2:** Implement

Create `packages/nexus-http-server-swoole/src/Signal/ShutdownSignalHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Signal;

use Closure;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server;
use Swoole\Process;

/**
 * @psalm-api
 *
 * Installs SIGTERM/SIGINT handlers that call $server->shutdown().
 * Idempotent — Swoole replaces previous handlers for the same signal.
 */
final class ShutdownSignalHandler
{
    public static function install(Server $server, LoggerInterface $logger): void
    {
        $handler = static function (int $signal) use ($server, $logger): void {
            $logger->info('Received shutdown signal', ['signal' => $signal]);
            $server->shutdown();
        };

        Process::signal(SIGTERM, $handler);
        Process::signal(SIGINT, $handler);
    }
}
```

- [ ] **Step 3:** Run + commit

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-server-swoole/tests/Unit/Signal/ 2>&1 | tail -5
make psalm && make phpcs
git add packages/nexus-http-server-swoole/src/Signal \
        packages/nexus-http-server-swoole/tests/Unit/Signal
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): ShutdownSignalHandler installs SIGTERM/SIGINT

Calls \$server->shutdown() on signal; logs via PSR-3. Tested with a
fake server stub since real Swoole\Process::signal requires a swoole
event loop."
```

---

## Phase 7: WebSocket primitives

**Outcome:** Public interfaces and value objects that downstream WebSocket code builds on. No Swoole event handling yet.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketFrame.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketContext.php` (interface)
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketHandler.php` (interface)
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketRoute.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/ConnectionTable.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/WebSocket/WebSocketFrameTest.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/WebSocket/ConnectionTableTest.php`

- [ ] **Step 1:** Implement `WebSocketFrame`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Immutable WebSocket frame. The 'kind' field reuses Swoole opcode integers
 * (1=text, 2=binary, 8=close, 9=ping, 10=pong).
 */
final readonly class WebSocketFrame
{
    public const int KIND_TEXT   = 1;
    public const int KIND_BINARY = 2;
    public const int KIND_CLOSE  = 8;
    public const int KIND_PING   = 9;
    public const int KIND_PONG   = 10;

    public function __construct(
        public int $kind,
        public string $text,
    ) {}

    public static function text(string $text): self
    {
        return new self(self::KIND_TEXT, $text);
    }

    public static function binary(string $data): self
    {
        return new self(self::KIND_BINARY, $data);
    }

    public function isText(): bool   { return $this->kind === self::KIND_TEXT; }
    public function isBinary(): bool { return $this->kind === self::KIND_BINARY; }
    public function isPing(): bool   { return $this->kind === self::KIND_PING; }
}
```

- [ ] **Step 2:** Test `WebSocketFrame`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function text_constructor(): void
    {
        $f = WebSocketFrame::text('hello');
        self::assertTrue($f->isText());
        self::assertFalse($f->isBinary());
        self::assertSame('hello', $f->text);
    }

    #[Test]
    public function binary_constructor(): void
    {
        $f = WebSocketFrame::binary("\x01\x02");
        self::assertTrue($f->isBinary());
        self::assertSame("\x01\x02", $f->text);
    }
}
```

- [ ] **Step 3:** Implement `WebSocketContext` interface

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Per-connection context handed to WebSocketHandler factories AND to channel
 * actors via ChannelConnectionOpened messages.
 *
 * Two impls: LocalWebSocketContext (worker mode + same-thread thread mode),
 * ThreadAwareWebSocketContext (thread mode, cross-thread send via WorkerTransport).
 */
interface WebSocketContext
{
    public function id(): int;
    public function request(): ServerRequestInterface;
    public function send(string $text): void;
    public function sendBinary(string $data): void;
    public function sendPing(): void;
    public function close(int $code = 1000, string $reason = ''): void;
}
```

- [ ] **Step 4:** Implement `WebSocketHandler` interface

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Handler-mode WebSocket interface. The factory passed to $app->webSocket()
 * is called once per connection on Open; returns an instance of this.
 */
interface WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void;
    public function onClose(int $closeCode): void;
}
```

- [ ] **Step 5:** Implement `WebSocketRoute`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Closure;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Immutable WebSocket route. Two flavours by mode:
 *   - HANDLER mode: $factory is a Closure(WebSocketContext): WebSocketHandler
 *   - CHANNEL mode: $props is a Props for the channel actor; $keyFrom names
 *     the path parameter that drives actor identity.
 */
final readonly class WebSocketRoute
{
    public const string MODE_HANDLER = 'handler';
    public const string MODE_CHANNEL = 'channel';

    public function __construct(
        public string $mode,
        public string $path,
        public ?Closure $factory,
        public ?Props $props,
        public ?string $keyFrom,
    ) {}

    public static function handler(string $path, Closure $factory): self
    {
        return new self(self::MODE_HANDLER, $path, $factory, null, null);
    }

    public static function channel(string $path, Props $props, string $keyFrom): self
    {
        return new self(self::MODE_CHANNEL, $path, null, $props, $keyFrom);
    }
}
```

- [ ] **Step 6:** Implement `ConnectionTable`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Per-worker/per-thread map: fd -> ConnectionEntry. Looked up on every
 * Message and Close event.
 *
 * For handler-mode connections, the entry's $handler is set.
 * For channel-mode connections, $channelName is set so the dispatcher knows
 * which channel actor to notify.
 */
final class ConnectionTable
{
    /** @var array<int, array{handler:?WebSocketHandler, channelName:?string, ctx:WebSocketContext}> */
    private array $entries = [];

    public function attachHandler(int $fd, WebSocketHandler $handler, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = ['handler' => $handler, 'channelName' => null, 'ctx' => $ctx];
    }

    public function attachChannel(int $fd, string $channelName, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = ['handler' => null, 'channelName' => $channelName, 'ctx' => $ctx];
    }

    /** @return array{handler:?WebSocketHandler, channelName:?string, ctx:WebSocketContext}|null */
    public function get(int $fd): ?array
    {
        return $this->entries[$fd] ?? null;
    }

    public function remove(int $fd): void
    {
        unset($this->entries[$fd]);
    }

    public function has(int $fd): bool
    {
        return isset($this->entries[$fd]);
    }

    /** @return list<int> */
    public function fds(): array
    {
        return array_keys($this->entries);
    }
}
```

- [ ] **Step 7:** Test `ConnectionTable`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class _StubCtx implements WebSocketContext
{
    public function __construct(public readonly int $id) {}
    public function id(): int { return $this->id; }
    public function request(): ServerRequestInterface { return new ServerRequest('GET', '/'); }
    public function send(string $text): void {}
    public function sendBinary(string $data): void {}
    public function sendPing(): void {}
    public function close(int $code = 1000, string $reason = ''): void {}
}

final class _StubHandler implements WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void {}
    public function onClose(int $closeCode): void {}
}

#[CoversClass(ConnectionTable::class)]
final class ConnectionTableTest extends TestCase
{
    #[Test]
    public function attach_handler_records_entry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(42);
        $h = new _StubHandler();
        $table->attachHandler(42, $h, $ctx);

        $entry = $table->get(42);
        self::assertNotNull($entry);
        self::assertSame($h, $entry['handler']);
        self::assertNull($entry['channelName']);
    }

    #[Test]
    public function attach_channel_records_entry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(7);
        $table->attachChannel(7, 'ws-channel-abc', $ctx);

        $entry = $table->get(7);
        self::assertNotNull($entry);
        self::assertSame('ws-channel-abc', $entry['channelName']);
        self::assertNull($entry['handler']);
    }

    #[Test]
    public function remove_drops_entry(): void
    {
        $table = new ConnectionTable();
        $ctx = new _StubCtx(99);
        $table->attachHandler(99, new _StubHandler(), $ctx);
        self::assertTrue($table->has(99));

        $table->remove(99);
        self::assertFalse($table->has(99));
        self::assertNull($table->get(99));
    }

    #[Test]
    public function fds_lists_all(): void
    {
        $table = new ConnectionTable();
        $table->attachHandler(1, new _StubHandler(), new _StubCtx(1));
        $table->attachHandler(2, new _StubHandler(), new _StubCtx(2));
        self::assertSame([1, 2], $table->fds());
    }
}
```

- [ ] **Step 8:** Run all phase-7 tests + lint + commit

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-server-swoole/tests/Unit/WebSocket/ 2>&1 | tail -5
make psalm && make phpcs
git add packages/nexus-http-server-swoole/src/WebSocket \
        packages/nexus-http-server-swoole/tests/Unit/WebSocket
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): WebSocket primitives — Frame, Context iface, Handler iface, Route, ConnectionTable

WebSocketFrame is the immutable frame value object with kind constants
matching Swoole opcodes. WebSocketContext + WebSocketHandler are public
contracts implemented by per-mode classes (next phases). WebSocketRoute
captures handler vs channel mode at registration. ConnectionTable is
the per-worker fd -> (handler|channelName, ctx) map."
```

---

## Phase 8: SwooleHttpApp DSL + WebSocketRouter + WebSocketRegistry

**Outcome:** `SwooleHttpApp` wraps a plain `HttpApp` and adds `webSocket(path, factory)` + `webSocketChannel(path, props, keyFrom)` registrars. `compile()` returns `SwooleCompiledHttpApp` (extends `CompiledHttpApp`, adds `webSocketRouter()` accessor).

**Files:**
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketRegistry.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/WebSocketRouter.php`
- Create: `packages/nexus-http-server-swoole/src/App/SwooleHttpApp.php`
- Create: `packages/nexus-http-server-swoole/src/App/SwooleCompiledHttpApp.php`
- Create: `packages/nexus-http-server-swoole/tests/Unit/App/SwooleHttpAppTest.php`

- [ ] **Step 1:** `WebSocketRegistry` — accumulates routes at boot

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Boot-time WebSocket route accumulator. Frozen at SwooleHttpApp::compile()
 * into a WebSocketRouter.
 */
final class WebSocketRegistry
{
    /** @var list<WebSocketRoute> */
    private array $routes = [];

    public function add(WebSocketRoute $route): void
    {
        $this->routes[] = $route;
    }

    /** @return list<WebSocketRoute> */
    public function all(): array
    {
        return $this->routes;
    }
}
```

- [ ] **Step 2:** `WebSocketRouter` — FastRoute over the upgrade path

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * @psalm-api
 *
 * FastRoute dispatcher over WebSocket upgrade paths. Returns a matched
 * WebSocketRoute plus extracted path parameters.
 */
final class WebSocketRouter
{
    /** @param array<int, WebSocketRoute> $routes */
    private function __construct(
        private readonly Dispatcher $delegate,
        private readonly array $routes,
    ) {}

    /** @param list<WebSocketRoute> $routes */
    public static function build(array $routes): self
    {
        $byId = [];
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes, &$byId): void {
            foreach ($routes as $id => $route) {
                $byId[$id] = $route;
                $r->addRoute('GET', $route->path, $id);
            }
        });

        return new self($dispatcher, $byId);
    }

    /** @return array{route: WebSocketRoute, params: array<string,string>}|null */
    public function match(string $path): ?array
    {
        $info = $this->delegate->dispatch('GET', $path);
        if ($info[0] !== Dispatcher::FOUND) {
            return null;
        }

        return ['route' => $this->routes[$info[1]], 'params' => $info[2]];
    }
}
```

- [ ] **Step 3:** `SwooleCompiledHttpApp`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\App;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Extends nexus-http's CompiledHttpApp to also expose a WebSocketRouter and
 * the ActorSystem (the WebSocket dispatcher needs both).
 */
final class SwooleCompiledHttpApp extends CompiledHttpApp
{
    public function __construct(
        RequestHandlerInterface $compiledHandler,
        ?EventDispatcherInterface $events,
        private readonly WebSocketRouter $webSocketRouter,
        private readonly ActorSystem $actorSystem,
    ) {
        parent::__construct($compiledHandler, $events);
    }

    public function webSocketRouter(): WebSocketRouter
    {
        return $this->webSocketRouter;
    }

    public function actorSystem(): ActorSystem
    {
        return $this->actorSystem;
    }
}
```

(Note: `CompiledHttpApp` from nexus-http has a private constructor accepting `RequestHandlerInterface $compiledHandler, ?EventDispatcherInterface $events`. If it's `final readonly`, we can't extend it. Verify: per Phase 10 of the nexus-http plan, `CompiledHttpApp` is `final readonly`. We CANNOT extend it. Alternative: composition.)

Replace the above with a composition-based design:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\App;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Wraps a CompiledHttpApp + WebSocketRouter + ActorSystem. Implements
 * RequestHandlerInterface by delegating to the wrapped CompiledHttpApp.
 * Server adapters use it for both HTTP and WebSocket dispatching.
 */
final readonly class SwooleCompiledHttpApp implements RequestHandlerInterface
{
    public function __construct(
        private CompiledHttpApp $http,
        private WebSocketRouter $webSocketRouter,
        private ActorSystem $actorSystem,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    public function webSocketRouter(): WebSocketRouter
    {
        return $this->webSocketRouter;
    }

    public function actorSystem(): ActorSystem
    {
        return $this->actorSystem;
    }
}
```

- [ ] **Step 4:** `SwooleHttpApp` — the wrapper DSL

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\App;

use Closure;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;

/**
 * @psalm-api
 *
 * Wraps nexus-http's HttpApp and adds WebSocket route registration.
 * compile() returns a SwooleCompiledHttpApp that exposes both the HTTP
 * RequestHandler and the WebSocketRouter for the runner.
 */
final class SwooleHttpApp
{
    private readonly WebSocketRegistry $webSockets;

    private function __construct(private readonly HttpApp $http)
    {
        $this->webSockets = new WebSocketRegistry();
    }

    public static function wrap(HttpApp $http): self
    {
        return new self($http);
    }

    public function http(): HttpApp
    {
        return $this->http;
    }

    /** @param Closure(WebSocketContext): \Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler $factory */
    public function webSocket(string $path, Closure $factory): self
    {
        $this->webSockets->add(WebSocketRoute::handler($path, $factory));

        return $this;
    }

    public function webSocketChannel(string $path, Props $props, string $keyFrom): self
    {
        $this->webSockets->add(WebSocketRoute::channel($path, $props, $keyFrom));

        return $this;
    }

    public function compile(): SwooleCompiledHttpApp
    {
        $compiled = $this->http->compile();
        $router   = WebSocketRouter::build($this->webSockets->all());

        return new SwooleCompiledHttpApp($compiled, $router, /* actorSystem */ $this->http->system());
    }
}
```

(Note: requires `HttpApp::system()` accessor. Check if it exists — per the nexus-http plan, HttpApp has `private readonly ActorSystem $system` but no public accessor. Adapt:)

Replace the last line with a constructor that takes the system explicitly:

```php
    /** @param ActorSystem $system */
    public static function wrap(HttpApp $http, \Monadial\Nexus\Core\Actor\ActorSystem $system): self
    {
        $instance = new self($http);
        $instance->system = $system;

        return $instance;
    }
```

Adjust the class accordingly. Simpler — keep the system as a constructor parameter to `wrap()`:

```php
final class SwooleHttpApp
{
    private readonly WebSocketRegistry $webSockets;

    private function __construct(
        private readonly HttpApp $http,
        private readonly \Monadial\Nexus\Core\Actor\ActorSystem $system,
    ) {
        $this->webSockets = new WebSocketRegistry();
    }

    public static function wrap(HttpApp $http, \Monadial\Nexus\Core\Actor\ActorSystem $system): self
    {
        return new self($http, $system);
    }

    // ... rest as before but use $this->system in compile()
}
```

- [ ] **Step 5:** Test `SwooleHttpApp`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\App;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SwooleHttpApp::class)]
#[CoversClass(SwooleCompiledHttpApp::class)]
final class SwooleHttpAppTest extends TestCase
{
    #[Test]
    public function wrap_and_compile_returns_compiled_app(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);
        $http->get('/x', static fn(): ResponseInterface => Response::ok());

        $app = SwooleHttpApp::wrap($http, $system)->compile();

        self::assertInstanceOf(SwooleCompiledHttpApp::class, $app);
    }

    #[Test]
    public function websocket_handler_route_registered(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);

        $app = SwooleHttpApp::wrap($http, $system)
            ->webSocket('/ws/echo', static fn(WebSocketContext $ctx): WebSocketHandler =>
                new class implements WebSocketHandler {
                    public function onMessage(\Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame $f): void {}
                    public function onClose(int $closeCode): void {}
                })
            ->compile();

        $match = $app->webSocketRouter()->match('/ws/echo');
        self::assertNotNull($match);
        self::assertSame(WebSocketRoute::MODE_HANDLER, $match['route']->mode);
    }

    #[Test]
    public function websocket_channel_route_registered_with_key(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $http = HttpApp::create($system);

        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $app = SwooleHttpApp::wrap($http, $system)
            ->webSocketChannel('/ws/channel/{channelId}', $props, keyFrom: 'channelId')
            ->compile();

        $match = $app->webSocketRouter()->match('/ws/channel/123');
        self::assertNotNull($match);
        self::assertSame(WebSocketRoute::MODE_CHANNEL, $match['route']->mode);
        self::assertSame('123', $match['params']['channelId']);
    }
}
```

- [ ] **Step 6:** Run + lint + commit

```bash
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit packages/nexus-http-server-swoole/tests/Unit/ 2>&1 | tail -5
make psalm && make phpcs
git add packages/nexus-http-server-swoole/src/WebSocket/WebSocketRegistry.php \
        packages/nexus-http-server-swoole/src/WebSocket/WebSocketRouter.php \
        packages/nexus-http-server-swoole/src/App \
        packages/nexus-http-server-swoole/tests/Unit/App
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): SwooleHttpApp DSL + WebSocketRouter

SwooleHttpApp wraps an HttpApp and adds webSocket()/webSocketChannel()
registrars. compile() returns SwooleCompiledHttpApp which composes the
inner CompiledHttpApp (HTTP) + a WebSocketRouter (FastRoute over
upgrade paths) + the ActorSystem reference (for channel actor spawn).
SwooleCompiledHttpApp implements RequestHandlerInterface by delegating
to the HTTP CompiledHttpApp."
```

---

## Phase 9: Worker-mode WebSocket wiring (handler mode end-to-end)

**Outcome:** `SwooleWorkerHttpServer::run()` now also registers `Open`/`Message`/`Close` event handlers when the factory returns a `SwooleCompiledHttpApp`. Implements `LocalWebSocketContext` and adds handler-mode dispatching. Integration test boots a real WebSocket echo server and asserts round-trip.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/WebSocket/LocalWebSocketContext.php`
- Modify: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php`
- Create: `tests/Integration/HttpSwoole/WorkerModeWebSocketHandlerTest.php`

- [ ] **Step 1:** Implement `LocalWebSocketContext`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server;

/**
 * @psalm-api
 *
 * Same-process WebSocket context. send() pushes directly via the local
 * Swoole\WebSocket\Server. Used in worker mode and in thread mode for
 * same-thread fds.
 */
final readonly class LocalWebSocketContext implements WebSocketContext
{
    public function __construct(
        private Server $server,
        private int $fd,
        private ServerRequestInterface $request,
    ) {}

    #[Override]
    public function id(): int { return $this->fd; }

    #[Override]
    public function request(): ServerRequestInterface { return $this->request; }

    #[Override]
    public function send(string $text): void
    {
        $this->server->push($this->fd, $text);
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        $this->server->push($this->fd, $data, WEBSOCKET_OPCODE_BINARY);
    }

    #[Override]
    public function sendPing(): void
    {
        $this->server->push($this->fd, '', WEBSOCKET_OPCODE_PING);
    }

    #[Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->server->disconnect($this->fd, $code, $reason);
    }
}
```

- [ ] **Step 2:** Update `SwooleWorkerHttpServer` to register WebSocket events when the compiled app is `SwooleCompiledHttpApp`

The full `run()` method now becomes substantial. Replace the entire file with this expanded version (preserve all the WorkerStart / Request / WorkerStop / restart-loop code from Phase 4, plus add a `WebSocket\Server` (not `Http\Server`) instance and event handlers).

Critical change: use `Swoole\WebSocket\Server` (subclass of `Http\Server`) so it handles both protocols:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\LocalWebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * @psalm-api
 *
 * Worker-mode HTTP + WebSocket runner. Boots ext-swoole's master + N worker
 * processes. Each worker runs the factory at WorkerStart to build a per-worker
 * CompiledHttpApp (or SwooleCompiledHttpApp for WebSocket support).
 *
 * Channel-actor WebSocket routes spawn the channel actor as WorkerLocal —
 * no cross-worker sharing. See spec for thread-mode alternative.
 */
final class SwooleWorkerHttpServer
{
    /** @var array<int, CompiledHttpApp> */
    private static array $appsByServerId = [];

    /** @var array<int, ActorSystem> */
    private static array $systemsByServerId = [];

    /** @var array<int, ConnectionTable> */
    private static array $connectionsByServerId = [];

    /** @var array<int, array{count:int, since:float}> */
    private static array $failureCounters = [];

    /** @param Closure(ActorSystem): CompiledHttpApp $factory */
    public static function run(SwooleWorkerConfig $config, Closure $factory): void
    {
        $server = new Server($config->host, $config->port);

        $settings = [
            'worker_num'    => $config->workers,
            'reactor_num'   => $config->reactorThreads,
            'max_request'   => $config->maxRequest,
            'max_conn'      => $config->maxConn,
            'dispatch_mode' => $config->dispatchMode,
        ];
        if ($config->logFile !== '') {
            $settings['log_file'] = $config->logFile;
        }
        $server->set($settings);

        $server->on('WorkerStart', static function (Server $s, int $workerId) use ($factory, $config): void {
            try {
                $system = ActorSystem::create("http-worker-{$workerId}", new SwooleRuntime());
                $app    = $factory($system);

                $serverId = spl_object_id($s);
                self::$appsByServerId[$serverId] = $app;
                self::$systemsByServerId[$serverId] = $system;
                self::$connectionsByServerId[$serverId] = new ConnectionTable();
            } catch (Throwable $e) {
                $config->logger->error('HTTP factory failed during WorkerStart', [
                    'exception' => $e,
                    'workerId' => $workerId,
                ]);
                self::recordFailureAndMaybeShutdown($s, $config);
            }
        });

        $server->on('Request', static function (Request $req, Response $res) use ($config): void {
            try {
                $serverId = self::resolveServerId();
                $app = self::$appsByServerId[$serverId] ?? null;
                if ($app === null) {
                    $res->status(503);
                    $res->end('Service not ready');

                    return;
                }
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request handling failed', ['exception' => $e]);
                if (!$res->isWritable()) {
                    return;
                }
                $res->status(500);
                $res->end('Internal Server Error');
            }
        });

        $server->on('Open', static function (Server $s, Request $req) use ($config): void {
            try {
                $serverId = spl_object_id($s);
                $app = self::$appsByServerId[$serverId] ?? null;
                if (!$app instanceof SwooleCompiledHttpApp) {
                    return;
                }
                $router = $app->webSocketRouter();
                $match  = $router->match($req->server['request_uri'] ?? '/');
                if ($match === null) {
                    $s->disconnect($req->fd, 1000, 'No WebSocket route');

                    return;
                }
                $route = $match['route'];
                $psr7  = SwooleRequestTranslator::toPsr7($req);
                $ctx   = new LocalWebSocketContext($s, $req->fd, $psr7);
                $table = self::$connectionsByServerId[$serverId];

                if ($route->mode === WebSocketRoute::MODE_HANDLER) {
                    $handler = ($route->factory)($ctx);
                    $table->attachHandler($req->fd, $handler, $ctx);
                } else {
                    // Channel mode handled in Phase 10/11
                    $config->logger->warning('Channel-mode WebSocket route not wired yet');
                }
            } catch (Throwable $e) {
                $config->logger->error('WebSocket Open failed', ['exception' => $e]);
                $s->disconnect($req->fd, 1011, 'Server error');
            }
        });

        $server->on('Message', static function (Server $s, SwooleFrame $frame) use ($config): void {
            try {
                $serverId = spl_object_id($s);
                $table = self::$connectionsByServerId[$serverId] ?? null;
                if ($table === null) {
                    return;
                }
                $entry = $table->get($frame->fd);
                if ($entry === null) {
                    return;
                }
                if ($entry['handler'] !== null) {
                    $entry['handler']->onMessage(new WebSocketFrame(
                        $frame->opcode === 2 ? WebSocketFrame::KIND_BINARY : WebSocketFrame::KIND_TEXT,
                        $frame->data,
                    ));
                }
            } catch (Throwable $e) {
                $config->logger->error('WebSocket Message failed', ['exception' => $e]);
            }
        });

        $server->on('Close', static function (Server $s, int $fd) use ($config): void {
            try {
                $serverId = spl_object_id($s);
                $table = self::$connectionsByServerId[$serverId] ?? null;
                if ($table === null) {
                    return;
                }
                $entry = $table->get($fd);
                if ($entry !== null && $entry['handler'] !== null) {
                    $entry['handler']->onClose(1000);
                }
                $table->remove($fd);
            } catch (Throwable $e) {
                $config->logger->error('WebSocket Close failed', ['exception' => $e]);
            }
        });

        $server->on('WorkerStop', static function (Server $s, int $workerId) use ($config): void {
            $serverId = spl_object_id($s);
            $system   = self::$systemsByServerId[$serverId] ?? null;
            if ($system !== null) {
                try {
                    $system->shutdown($config->shutdownTimeout);
                } catch (Throwable $e) {
                    $config->logger->error('System shutdown failed', ['exception' => $e, 'workerId' => $workerId]);
                }
            }
            unset(
                self::$appsByServerId[$serverId],
                self::$systemsByServerId[$serverId],
                self::$connectionsByServerId[$serverId],
            );
        });

        if ($config->installSignalHandlers) {
            ShutdownSignalHandler::install($server, $config->logger);
        }

        $server->start();
    }

    /**
     * Best-effort: pick the first registered server id (single-server is typical).
     * Multi-server-per-process is unsupported.
     */
    private static function resolveServerId(): int
    {
        $first = array_key_first(self::$appsByServerId);

        return $first ?? 0;
    }

    private static function recordFailureAndMaybeShutdown(Server $server, SwooleWorkerConfig $config): void
    {
        $serverId = spl_object_id($server);
        $now      = microtime(true);
        $bucket   = self::$failureCounters[$serverId] ?? ['count' => 0, 'since' => $now];

        if ($now - $bucket['since'] > 5.0) {
            $bucket = ['count' => 1, 'since' => $now];
        } else {
            $bucket['count']++;
        }
        self::$failureCounters[$serverId] = $bucket;

        if ($bucket['count'] >= 3) {
            $config->logger->error(
                'HTTP factory failed during worker boot 3 times in 5s — shutting down master.',
            );
            $server->shutdown();
        }
    }
}
```

- [ ] **Step 3:** Integration test — handler-mode WebSocket echo

Create `tests/Integration/HttpSwoole/WorkerModeWebSocketHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

final class _EchoHandler implements WebSocketHandler
{
    public function __construct(private readonly WebSocketContext $ctx) {}

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    public function onClose(int $code): void {}
}

#[CoversNothing]
final class WorkerModeWebSocketHandlerTest extends TestCase
{
    #[Test]
    public function handler_mode_echoes_message(): void
    {
        $port = $this->findFreePort();

        $pid = pcntl_fork();
        if ($pid === 0) {
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)->workers(1)->installSignalHandlers(false),
                factory: static function (ActorSystem $system) {
                    $http = HttpApp::create($system);
                    return SwooleHttpApp::wrap($http, $system)
                        ->webSocket('/ws/echo', static fn(WebSocketContext $ctx) => new _EchoHandler($ctx))
                        ->compile();
                },
            );
            exit(0);
        }

        usleep(500_000);

        try {
            $reply = null;
            \Co\run(function () use ($port, &$reply): void {
                $client = new Client('127.0.0.1', $port);
                $client->upgrade('/ws/echo');
                $client->push('hi');
                $frame = $client->recv(2.0);
                $reply = $frame->data ?? null;
                $client->close();
            });
            self::assertSame('echo:hi', $reply);
        } finally {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
    }

    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $_, $port);
        socket_close($sock);

        return (int) $port;
    }
}
```

- [ ] **Step 4:** Run + commit

```bash
make test-http-swoole 2>&1 | tail -10
git add packages/nexus-http-server-swoole/src/WebSocket/LocalWebSocketContext.php \
        packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php \
        tests/Integration/HttpSwoole/WorkerModeWebSocketHandlerTest.php
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): handler-mode WebSocket wiring in worker mode

SwooleWorkerHttpServer now uses Swoole\WebSocket\Server (extends
Http\Server) and registers Open/Message/Close handlers. Handler-mode
routes invoke the factory once per connection, store the handler in
ConnectionTable keyed by fd. Integration test echoes a message
through a real handshake round-trip."
```

---

(Plan continues in subsequent phases — Channel actor mode (Phase 10/11), Threads package bootstrap (Phase 12), WorkerNodePoolSingletonSpawner (Phase 13), thread runner (Phase 14), thread integration (Phase 15), cross-thread WebSocket (Phase 16-17), Performance harness (Phase 18-20), Documentation + final verification (Phase 21).)

---

## Phase 10: Channel actor mode (worker-mode)

**Outcome:** `webSocketChannel(path, props, keyFrom)` registration becomes wired end-to-end. On `Open`, the framework computes the channel actor name from the path param via `xxh3` hash, spawns the actor as `WorkerLocal` if absent (cached in a per-server `ChannelActorRegistry`), and sends `ChannelConnectionOpened`. `Message` and `Close` events dispatch to the channel actor.

**Files:**
- Create: `packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelConnectionOpened.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelMessageReceived.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/Message/ChannelConnectionClosed.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/ChannelActorRegistry.php`
- Create: `packages/nexus-http-server-swoole/src/WebSocket/ChannelActorNameResolver.php`
- Modify: `packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php`

- [ ] **Step 1:** Channel actor messages (3 files)

`ChannelConnectionOpened.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class ChannelConnectionOpened
{
    public function __construct(
        public int $fd,
        public WebSocketContext $ctx,
        public ServerRequestInterface $request,
    ) {}
}
```

`ChannelMessageReceived.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;

/** @psalm-api */
final readonly class ChannelMessageReceived
{
    public function __construct(public int $fd, public WebSocketFrame $frame) {}
}
```

`ChannelConnectionClosed.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

/** @psalm-api */
final readonly class ChannelConnectionClosed
{
    public function __construct(public int $fd, public int $closeCode) {}
}
```

- [ ] **Step 2:** `ChannelActorNameResolver`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Stable per-key actor name. Uses xxh3 (PHP's hash() supports it as of 8.1)
 * truncated to 16 hex chars — collision-resistant enough for in-process
 * actor naming, URL-character safe.
 */
final class ChannelActorNameResolver
{
    public static function resolve(string $rawKey): string
    {
        $hash = bin2hex(substr(hash('xxh3', $rawKey, true), 0, 8));

        return "ws-channel-{$hash}";
    }
}
```

- [ ] **Step 3:** `ChannelActorRegistry`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Per-worker cache: actor name -> ActorRef. Lazy-spawns the channel actor
 * on first lookup; subsequent connections to the same channel reuse.
 */
final class ChannelActorRegistry
{
    /** @var array<string, ActorRef<object>> */
    private array $cache = [];

    public function __construct(private readonly ActorSystem $system) {}

    public function resolveOrSpawn(string $name, Props $props): ActorRef
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $ref = $this->system->spawn($props, $name);
        $this->cache[$name] = $ref;

        return $ref;
    }

    public function remove(string $name): void
    {
        unset($this->cache[$name]);
    }
}
```

- [ ] **Step 4:** Wire into `SwooleWorkerHttpServer`

Add a static `ChannelActorRegistry[]` keyed by server id, initialize in `WorkerStart`:

```php
    /** @var array<int, ChannelActorRegistry> */
    private static array $channelsByServerId = [];
```

Initialize in WorkerStart (after `$system` is created):

```php
    self::$channelsByServerId[$serverId] = new ChannelActorRegistry($system);
```

Update the `Open` event to handle channel mode:

```php
    if ($route->mode === WebSocketRoute::MODE_HANDLER) {
        $handler = ($route->factory)($ctx);
        $table->attachHandler($req->fd, $handler, $ctx);
    } else {
        // Channel mode
        $key = $match['params'][$route->keyFrom ?? ''] ?? '';
        $actorName = ChannelActorNameResolver::resolve($key);
        $registry = self::$channelsByServerId[$serverId];
        $actor = $registry->resolveOrSpawn($actorName, $route->props);
        $actor->tell(new ChannelConnectionOpened($req->fd, $ctx, $psr7));
        $table->attachChannel($req->fd, $actorName, $ctx);
    }
```

Update `Message` and `Close` to forward to channel actor when applicable:

```php
// Message event:
if ($entry['handler'] !== null) {
    $entry['handler']->onMessage($frame);
} elseif ($entry['channelName'] !== null) {
    $registry = self::$channelsByServerId[$serverId];
    $actor = $registry->resolveOrSpawn($entry['channelName'], /* not needed; already spawned */ $route->props);
    // Actually: we need to keep the channel actor ref directly. Refactor:
    // store the ActorRef in the connection entry, not the name.
}
```

This pushes a small refactor — the connection entry should also store the channel actor ref. Update `ConnectionTable::attachChannel`:

```php
    public function attachChannel(int $fd, ActorRef $actor, string $channelName, WebSocketContext $ctx): void
    {
        $this->entries[$fd] = [
            'handler'     => null,
            'channelName' => $channelName,
            'channelActor' => $actor,
            'ctx'         => $ctx,
        ];
    }
```

And the lookup type for the dispatcher:

```php
/** @return array{handler:?WebSocketHandler, channelName:?string, channelActor:?ActorRef<object>, ctx:WebSocketContext}|null */
```

Update Message/Close dispatch to use `$entry['channelActor']->tell(...)`.

The full revised SwooleWorkerHttpServer is omitted here for brevity — incorporate the channel-actor path into the existing skeleton.

- [ ] **Step 5:** Lint + commit

```bash
make psalm && make phpcs
git add packages/nexus-http-server-swoole/src/WebSocket/Message \
        packages/nexus-http-server-swoole/src/WebSocket/ChannelActorRegistry.php \
        packages/nexus-http-server-swoole/src/WebSocket/ChannelActorNameResolver.php \
        packages/nexus-http-server-swoole/src/WebSocket/ConnectionTable.php \
        packages/nexus-http-server-swoole/src/Server/SwooleWorkerHttpServer.php
git -c commit.gpgsign=false commit -m "feat(http-server-swoole): channel-actor mode in worker mode

webSocketChannel routes spawn one actor per channel key, looked up via
xxh3 hash for URL-safe stable naming. Connection events forward to the
channel actor as ChannelConnectionOpened/MessageReceived/Closed messages.
ChannelActorRegistry caches per-key refs; subsequent connections to the
same channel reuse the existing actor instance. WorkerLocal semantics —
documented limitation that state doesn't sync across workers."
```

---

## Phase 11: Channel actor worker-mode integration test

**Outcome:** End-to-end test: register a chat-style channel actor; open two connections to `/ws/channel/room42`; send a message from connection A; assert connection B receives a broadcast.

**Files:**
- Create: `tests/Integration/HttpSwoole/WorkerModeWebSocketChannelTest.php`
- Create: `tests/Integration/HttpSwoole/Support/ChannelChatBehavior.php`

- [ ] **Step 1:** Sample `ChannelChatBehavior` for the test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole\Support;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelMessageReceived;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;

final class ChannelChatBehavior
{
    public static function props(): Props
    {
        return Props::fromBehavior(Behavior::withState(
            initialState: ['ctx' => []],
            receive: static function (ActorContext $ctx, object $msg, array $state) {
                if ($msg instanceof ChannelConnectionOpened) {
                    /** @var array<int, WebSocketContext> $newCtx */
                    $newCtx = $state['ctx'];
                    $newCtx[$msg->fd] = $msg->ctx;

                    return BehaviorWithState::next(['ctx' => $newCtx]);
                }
                if ($msg instanceof ChannelMessageReceived) {
                    foreach ($state['ctx'] as $fd => $c) {
                        if ($fd !== $msg->fd) {
                            $c->send($msg->frame->text);
                        }
                    }

                    return BehaviorWithState::same();
                }
                if ($msg instanceof ChannelConnectionClosed) {
                    $newCtx = $state['ctx'];
                    unset($newCtx[$msg->fd]);

                    return $newCtx === []
                        ? BehaviorWithState::stopped()
                        : BehaviorWithState::next(['ctx' => $newCtx]);
                }

                return BehaviorWithState::same();
            },
        ));
    }
}
```

- [ ] **Step 2:** Integration test

Create `tests/Integration/HttpSwoole/WorkerModeWebSocketChannelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ChannelChatBehavior;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;

#[CoversNothing]
final class WorkerModeWebSocketChannelTest extends TestCase
{
    #[Test]
    public function channel_broadcasts_within_same_worker(): void
    {
        $port = $this->findFreePort();

        $pid = pcntl_fork();
        if ($pid === 0) {
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)->workers(1)->installSignalHandlers(false),
                factory: static function (ActorSystem $system) {
                    $http = HttpApp::create($system);
                    return SwooleHttpApp::wrap($http, $system)
                        ->webSocketChannel(
                            '/ws/channel/{channelId}',
                            ChannelChatBehavior::props(),
                            keyFrom: 'channelId',
                        )
                        ->compile();
                },
            );
            exit(0);
        }

        usleep(500_000);

        $receivedByB = null;
        try {
            \Co\run(function () use ($port, &$receivedByB): void {
                $a = new Client('127.0.0.1', $port);
                $a->upgrade('/ws/channel/room42');
                $b = new Client('127.0.0.1', $port);
                $b->upgrade('/ws/channel/room42');
                usleep(100_000);

                $a->push('hello-from-a');
                $f = $b->recv(2.0);
                $receivedByB = $f->data ?? null;

                $a->close();
                $b->close();
            });

            self::assertSame('hello-from-a', $receivedByB);
        } finally {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
    }

    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $_, $port);
        socket_close($sock);

        return (int) $port;
    }
}
```

- [ ] **Step 3:** Run + commit

```bash
make test-http-swoole 2>&1 | tail -10
git add tests/Integration/HttpSwoole/Support/ChannelChatBehavior.php \
        tests/Integration/HttpSwoole/WorkerModeWebSocketChannelTest.php
git -c commit.gpgsign=false commit -m "test(http-server-swoole): channel actor broadcast (worker mode)

Two connections to /ws/channel/room42 share a channel actor; message
from A broadcasts to B. Confirms WorkerLocal channel-actor semantics
and the full Open/Message/Close pipeline through SwooleWorkerHttpServer."
```

---

## Phase 12: Bootstrap nexus-http-server-swoole-threads package

**Outcome:** Empty threads package wired into the monorepo. Independent unit-swoole testsuite entry. Deptrac layer.

**Files:**
- Create: `packages/nexus-http-server-swoole-threads/composer.json`
- Create: `packages/nexus-http-server-swoole-threads/README.md`
- Create: `packages/nexus-http-server-swoole-threads/src/.gitkeep`
- Create: `packages/nexus-http-server-swoole-threads/tests/Unit/.gitkeep`
- Modify: root `composer.json` — add autoload entries
- Modify: `phpunit.xml` — add to `unit-swoole`
- Modify: `deptrac.yaml` — add `HttpServerSwooleThreads` layer
- Modify: phpunit.xml `<source>` — add coverage dir

- [ ] **Step 1:** Skeleton + composer.json

```bash
mkdir -p packages/nexus-http-server-swoole-threads/src \
         packages/nexus-http-server-swoole-threads/tests/Unit
touch packages/nexus-http-server-swoole-threads/src/.gitkeep \
      packages/nexus-http-server-swoole-threads/tests/Unit/.gitkeep
```

`packages/nexus-http-server-swoole-threads/composer.json`:

```json
{
    "name": "nexus-actors/http-server-swoole-threads",
    "description": "Thread-mode HTTP server using nexus-worker-pool-swoole; adds PoolSingletonSpawner bridge.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/http": "dev-main",
        "nexus-actors/http-server-swoole": "dev-main",
        "nexus-actors/worker-pool": "dev-main",
        "nexus-actors/worker-pool-swoole": "dev-main"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Server\\Swoole\\Threads\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Http\\Server\\Swoole\\Threads\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2:** README, root composer.json autoload, phpunit testsuite + coverage source, deptrac layer

(Identical pattern to Phase 0; deptrac ruleset for the new layer:)

```yaml
  HttpServerSwooleThreads:
    - Core
    - Runtime
    - Http
    - HttpServerSwoole
    - WorkerPool
    - WorkerPoolSwoole
```

- [ ] **Step 3:** Run install + test-unit; commit

```bash
make install
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit-swoole 2>&1 | tail -5
git add packages/nexus-http-server-swoole-threads composer.json phpunit.xml deptrac.yaml
git -c commit.gpgsign=false commit -m "feat(http-server-swoole-threads): bootstrap package skeleton

Empty package wired into autoload, unit-swoole testsuite, and deptrac.
Depends on http-server-swoole + worker-pool-swoole."
```

---

## Phase 13: WorkerNodePoolSingletonSpawner

**Outcome:** The bridge class — implements nexus-http's `PoolSingletonSpawner` by delegating to `WorkerNode::spawn`. The whole point of this package.

**Files:**
- Create: `packages/nexus-http-server-swoole-threads/src/Actor/WorkerNodePoolSingletonSpawner.php`
- Create: `packages/nexus-http-server-swoole-threads/tests/Unit/Actor/WorkerNodePoolSingletonSpawnerTest.php`

- [ ] **Step 1:** Implement

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Override;

/**
 * @psalm-api
 *
 * Adapts WorkerNode::spawn (routes via the consistent hash ring across
 * threads) to nexus-http's PoolSingletonSpawner contract.
 *
 * Pass to HttpApp::withPoolSingletonSpawner() inside the factory closure
 * to enable pool-singleton actor mode in thread-mode apps.
 */
final readonly class WorkerNodePoolSingletonSpawner implements PoolSingletonSpawner
{
    public function __construct(private WorkerNode $node) {}

    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        return $this->node->spawn($props, $name);
    }
}
```

- [ ] **Step 2:** Test (using existing TestRuntime-based pattern from worker-pool-swoole)

Use the same pattern as `packages/nexus-worker-pool/tests/Unit` — boot a single-node hash ring, verify spawn returns a usable ref.

(Test code omitted for plan brevity — should be ~30 lines following the pattern in `packages/nexus-worker-pool/tests/Unit/WorkerNodeTest.php`.)

- [ ] **Step 3:** Lint + commit

```bash
make psalm && make phpcs
git add packages/nexus-http-server-swoole-threads/src/Actor \
        packages/nexus-http-server-swoole-threads/tests/Unit/Actor
git -c commit.gpgsign=false commit -m "feat(http-server-swoole-threads): WorkerNodePoolSingletonSpawner

15-line bridge adapting WorkerNode::spawn (consistent-hash routing) to
nexus-http's PoolSingletonSpawner interface. The raison d'etre of the
threads package."
```

---

## Phase 14: SwooleThreadHttpServer + SwooleThreadConfig

**Outcome:** Thread-mode runner attached via `onThread()` from inside `configure()` of `WorkerPoolApp`. Reuses worker-mode bridge/event-handler patterns; one `Swoole\WebSocket\Server` per thread with `SO_REUSEPORT`.

**Files:**
- Create: `packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadConfig.php`
- Create: `packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadHttpServer.php`

- [ ] **Step 1:** `SwooleThreadConfig` (minimal — host/port/maxRequest/shutdownTimeout/logger)

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @psalm-api */
final readonly class SwooleThreadConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $maxRequest,
        public Duration $shutdownTimeout,
        public LoggerInterface $logger,
    ) {}

    public static function bind(string $host, int $port = 8080): self
    {
        return new self($host, $port, 0, Duration::seconds(10), new NullLogger());
    }

    public function maxRequest(int $n): self
    {
        return new self($this->host, $this->port, $n, $this->shutdownTimeout, $this->logger);
    }

    public function shutdownTimeout(Duration $d): self
    {
        return new self($this->host, $this->port, $this->maxRequest, $d, $this->logger);
    }

    public function logger(LoggerInterface $log): self
    {
        return new self($this->host, $this->port, $this->maxRequest, $this->shutdownTimeout, $log);
    }
}
```

- [ ] **Step 2:** `SwooleThreadHttpServer::onThread`

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * @psalm-api
 *
 * Per-thread HTTP+WebSocket runner. Called from WorkerPoolApp::configure()
 * inside each thread. Reuses the worker-mode bridge + event-handler logic;
 * the actor system is the one already owned by WorkerNode.
 *
 * Binds a Swoole\WebSocket\Server with SO_REUSEPORT so all threads share
 * the same listening address; the kernel load-balances connections.
 */
final class SwooleThreadHttpServer
{
    /** @param Closure(ActorSystem, WorkerNode): CompiledHttpApp $factory */
    public static function onThread(
        WorkerNode $node,
        SwooleThreadConfig $config,
        Closure $factory,
    ): void {
        $system = $node->system();
        $app    = $factory($system, $node);

        $server = new Server($config->host, $config->port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
        $settings = [
            'open_http_protocol' => true,
            'enable_reuse_port'  => true,
            'worker_num'         => 0,
            'max_request'        => $config->maxRequest,
        ];
        $server->set($settings);
        $table = new ConnectionTable();

        $server->on('Request', static function (Request $req, Response $res) use ($app, $config): void {
            try {
                $psr7 = SwooleRequestTranslator::toPsr7($req);
                SwooleResponseWriter::write($app->handle($psr7), $res);
            } catch (Throwable $e) {
                $config->logger->error('Request failed', ['exception' => $e]);
                if ($res->isWritable()) {
                    $res->status(500);
                    $res->end('Internal Server Error');
                }
            }
        });

        // WebSocket events delegated to similar logic as worker mode, but with
        // ThreadAwareWebSocketContext for channel-mode (added in Phase 16).

        $server->start();
    }
}
```

- [ ] **Step 3:** Lint + commit

```bash
make psalm && make phpcs
git add packages/nexus-http-server-swoole-threads/src/Server
git -c commit.gpgsign=false commit -m "feat(http-server-swoole-threads): SwooleThreadHttpServer + SwooleThreadConfig

onThread(node, config, factory) called from configure() in WorkerPoolApp.
Binds Swoole\WebSocket\Server per-thread with SO_REUSEPORT; reuses
worker-mode bridge for HTTP. WebSocket event handlers stub for now —
filled in by Phase 16 (cross-thread broadcast support)."
```

---

## Phase 15: Thread-mode integration test (HTTP + pool-singleton)

**Outcome:** WorkerPoolApp boots 2 threads with `SwooleThreadHttpServer::onThread`. HTTP request answered. Pool-singleton actor accessible from any thread.

**Files:**
- Create: `tests/Integration/HttpSwoole/ThreadModeHttpIntegrationTest.php`

Detailed test extends `WorkerPoolApp`, configures the HTTP server with a pool-singleton actor, and drives requests via `Swoole\Coroutine\Http\Client`. Asserts the actor receives messages regardless of which thread accepted the connection.

(Test code follows the pattern of `packages/nexus-worker-pool-swoole/tests/Integration` already in the codebase — refer there for the exact `WorkerPoolApp` extension pattern.)

- [ ] **Step 1:** Write test (full file)
- [ ] **Step 2:** Run `make test-http-swoole`
- [ ] **Step 3:** Commit

---

## Phase 16: Cross-thread WebSocket broadcast — ThreadAwareWebSocketContext + WebSocketFramePush

**Outcome:** `ThreadAwareWebSocketContext::send` routes via `WorkerTransport` when the target fd is on a different thread. `WebSocketFramePush` system message handled by each thread's WebSocket router.

**Files:**
- Create: `packages/nexus-http-server-swoole-threads/src/WebSocket/Message/WebSocketFramePush.php`
- Create: `packages/nexus-http-server-swoole-threads/src/WebSocket/ThreadAwareWebSocketContext.php`
- Modify: `packages/nexus-http-server-swoole-threads/src/Server/SwooleThreadHttpServer.php` — register handler for `WebSocketFramePush` system messages via WorkerNode

Detailed implementation follows.

- [ ] Steps + tests + commit

---

## Phase 17: Thread-mode WebSocket integration tests

**Outcome:** Handler-mode WebSocket round-trip verified in SWOOLE_THREAD mode (2 threads, 1 connection, echo `hi` → `echo:hi`). Proves the Open/Message/Close pipeline (router actors, ConnectionTable, ThreadAwareWebSocketContext) is correctly wired in `SwooleThreadHttpServer`.

**Deviation from original outcome:** Cross-thread broadcast across 100 distributed connections is deferred. Phase 16 documents the v1 limitation: channel-mode actors are thread-local because `ChannelConnectionOpened` (carrying `WebSocketContext` + Swoole `Request`) is not serialization-safe across `Thread\Queue`. Handler mode is the supported path in thread mode for v1; the broader broadcast scenario is future work.

**Files:**
- Create: `tests/Integration/HttpSwoole/Support/thread_websocket_server_bootstrap.php`
- Create: `tests/Integration/HttpSwoole/ThreadModeWebSocketIntegrationTest.php`

- [x] Full integration test + run + commit

---

## Phase 18: Performance harness shared classes

**Outcome:** `LatencyRecorder`, `PerfReport`, `FreePort` utilities used by all perf tests.

**Files:**
- Create: `tests/Performance/HttpSwoole/Support/LatencyRecorder.php`
- Create: `tests/Performance/HttpSwoole/Support/PerfReport.php`
- Create: `tests/Performance/HttpSwoole/Support/FreePort.php`
- Modify: `phpunit.xml` — add `performance-http-swoole` testsuite
- Modify: `Makefile` — add `perf-http-swoole` target

- [ ] Steps for each support class, plus a smoke test asserting they work

---

## Phase 19: HTTP perf tests (worker + thread)

**Outcome:** Two tests, one per mode, asserting P99 < 5ms + throughput > 5000 rps under regression workload.

**Files:**
- Create: `tests/Performance/HttpSwoole/WorkerHttpThroughputTest.php`
- Create: `tests/Performance/HttpSwooleThreads/ThreadHttpThroughputTest.php`

- [ ] Steps + run + commit

---

## Phase 20: WebSocket perf tests (worker channel broadcast + thread cross-thread broadcast)

**Outcome:** Two tests, one per mode, asserting P99 broadcast fanout < 50ms.

**Files:**
- Create: `tests/Performance/HttpSwoole/WorkerWebSocketBroadcastTest.php`
- Create: `tests/Performance/HttpSwooleThreads/ThreadWebSocketChannelBroadcastTest.php`

- [ ] Steps + run + commit

---

## Phase 21: READMEs + final verification

**Outcome:** Expanded READMEs for both packages with quickstart examples (HTTP route, WebSocket handler, channel actor). Final lint + test matrix.

**Files:**
- Modify: `packages/nexus-http-server-swoole/README.md`
- Modify: `packages/nexus-http-server-swoole-threads/README.md`

- [ ] Final verification commands

```bash
timeout 90 docker exec -i nexus-php-1 vendor/bin/psalm 2>&1 | tail -5
timeout 90 docker exec -i nexus-php-1 vendor/bin/phpcs 2>&1 | tail -5
timeout 120 docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
timeout 60 docker exec -i nexus-php-1 vendor/bin/phpunit --testsuite=unit-swoole 2>&1 | tail -5
timeout 90 docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole 2>&1 | tail -5
timeout 60 docker exec -i nexus-php-1 php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse 2>&1 | tail -8
```

Expected: all green; deptrac 0 violations.

- [ ] Commit

```bash
git add packages/nexus-http-server-swoole/README.md \
        packages/nexus-http-server-swoole-threads/README.md
git -c commit.gpgsign=false commit -m "docs(http-server-swoole): expanded READMEs for both packages

Quickstart examples covering HTTP worker mode, WebSocket handler mode,
channel-actor mode in worker mode, and thread mode with pool-singleton
actors via WorkerNodePoolSingletonSpawner."
```

---

## What's NOT in scope for v1

| Item | Disposition |
|---|---|
| TLS / HTTP/2 / HTTP/3 | Use reverse proxy |
| Process supervision (daemonize, PID files) | systemd / supervisord / docker |
| Hot reload of CompiledHttpApp without worker restart | Use max_request recycle |
| Cross-worker WebSocket broadcast in worker mode | v2: Swoole Table or pub/sub |
| WebSocket compression (permessage-deflate) | v2 |
| WebSocket subprotocol negotiation beyond header echo | v2 |
| Performance test result delta on PRs | Future CI tooling |
