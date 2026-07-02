# nexus-http-auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `nexus-actors/http-auth` — pluggable AuthN+AuthZ for the Nexus HTTP stack with a working JWT authenticator, 7 attributes, and unified HTTP+WS auth path.

**Architecture:** A new optional package providing `Principal`/`Authenticator`/`Authorizer` contracts, `AuthenticationMiddleware` (stamps Principal on the request) and `AuthorizationMiddleware` (enforces 401/403 from declarative attributes). Three minimal patches to `nexus-http` and one to `nexus-http-ws` add `#[FromPrincipal]` DI and expose the resolved handler class on the request. `nexus-http-ws` is a soft dep — WS works because the auth middleware runs against the upgrade request before `101 Switching Protocols`.

**Tech Stack:** PHP 8.5, lcobucci/jwt ^5 (HMAC/RSA/ECDSA/EdDSA), PSR-7/15, PHPUnit 13, Psalm strict, GrumPHP gates. Docker (no host PHP).

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| Package boundary, composer.json, autoload | T1 |
| Contracts (Principal, Authenticator, Authorizer, TokenExtractor, AuthChallenge) | T2, T3, T4 |
| Exceptions | T5 |
| SimplePrincipal | T6 |
| Token extractors (Bearer, Header, Cookie) | T7 |
| Auth attributes (RequiresAuth, RequiresScope, RequiresAnyScope, RequiresRole, RequiresAnyRole, Authorize, FromPrincipal) | T8 |
| `nexus-http` patch — `#[FromPrincipal]` in HandlerResolver | T9 |
| `nexus-http` patch — handler class on request attribute | T10 |
| `nexus-http-ws` patch — `#[FromPrincipal]` in HandlerInstantiator | T11 |
| StaticTokenAuthenticator + ChainAuthenticator | T12 |
| AuthenticationMiddleware | T13 |
| AuthorizationMiddleware | T14 |
| JwtAuthenticator | T15 |
| Integration tests via HttpTestClient | T16 |
| Documentation pages | T17 |

---

## File structure

```
packages/nexus-http-auth/
├── composer.json
├── src/
│   ├── Principal.php                                  # interface
│   ├── Authenticator.php                              # interface
│   ├── Authorizer.php                                 # interface
│   ├── TokenExtractor.php                             # interface
│   ├── AuthChallenge.php                              # readonly value object
│   ├── Exception/
│   │   ├── AuthException.php                          # abstract base
│   │   ├── Unauthenticated.php
│   │   ├── Forbidden.php
│   │   ├── AuthMiddlewareNotRegisteredException.php
│   │   └── InvalidAuthorizerException.php
│   ├── Principal/
│   │   └── SimplePrincipal.php
│   ├── Attribute/
│   │   ├── FromPrincipal.php
│   │   ├── RequiresAuth.php
│   │   ├── RequiresScope.php
│   │   ├── RequiresAnyScope.php
│   │   ├── RequiresRole.php
│   │   ├── RequiresAnyRole.php
│   │   └── Authorize.php
│   ├── Extractor/
│   │   ├── BearerTokenExtractor.php
│   │   ├── HeaderTokenExtractor.php
│   │   └── CookieTokenExtractor.php
│   ├── Authenticator/
│   │   ├── StaticTokenAuthenticator.php
│   │   ├── ChainAuthenticator.php
│   │   └── JwtAuthenticator.php
│   └── Middleware/
│       ├── AuthenticationMiddleware.php
│       └── AuthorizationMiddleware.php
└── tests/
    ├── Support/
    │   ├── Fixtures.php
    │   └── InMemoryAuthenticator.php
    ├── Unit/
    │   ├── AuthChallengeTest.php
    │   ├── Principal/SimplePrincipalTest.php
    │   ├── Extractor/BearerTokenExtractorTest.php
    │   ├── Extractor/HeaderTokenExtractorTest.php
    │   ├── Extractor/CookieTokenExtractorTest.php
    │   ├── Authenticator/StaticTokenAuthenticatorTest.php
    │   ├── Authenticator/ChainAuthenticatorTest.php
    │   ├── Authenticator/JwtAuthenticatorTest.php
    │   ├── Middleware/AuthenticationMiddlewareTest.php
    │   └── Middleware/AuthorizationMiddlewareTest.php
    └── Integration/
        └── HttpAuthIntegrationTest.php
```

**Patched files (existing packages):**
- `packages/nexus-http/src/Handler/ParamMetadata.php` — add `KIND_FROM_PRINCIPAL` constant
- `packages/nexus-http/src/Handler/HandlerResolver.php` — recognize `#[FromPrincipal]` (by FQCN string literal to avoid hard dep)
- `packages/nexus-http/src/Middleware/RouterMiddleware.php` — stamp `'_resolvedHandlerClass'` on request
- `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php` — mirror `#[FromPrincipal]` recognition

**Modified files (root):**
- `composer.json` — register `Monadial\\Nexus\\Http\\Auth\\` autoload + tests
- `website/docs/packages/http-auth.md` (new) and `website/docs/http/auth.md` (new)
- `website/sidebars.js`

---

## Conventions used throughout this plan

- **Docker for everything.** No host PHP. Test command:
  ```bash
  docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/<file>.php
  ```
- **GrumPHP gates each commit** (Psalm, PHPCS, PHP-CS-Fixer, PHPUnit unit suite). Pre-commit hook runs inside the container.
- **Commit format:** `feat(http-auth): <what>` / `fix(http-auth): <what>` / `feat(http,http-auth): <what>` for cross-package patches. **Never** add `Co-Authored-By: Claude`.
- **GPG signing:** the test environment uses `git -c commit.gpgsign=false commit -m ...` because GPG isn't available in CI.
- **Final classes by default, readonly value objects.** Per project CLAUDE.md.
- **PER-CS2.0 + Slevomat extensions.** Arrays with string keys sorted alphabetically. Blank line before `if`/`for`/`try`. Ternaries multi-line.
- **Override attribute** required on every method that overrides — `#[Override]` directly above the method.
- **Test pattern:** `#[CoversClass(Foo::class)]` on the test class, `#[Test]` on each test method, snake_case method names.

---

## Task 1: Package scaffold

**Files:**
- Create: `packages/nexus-http-auth/composer.json`
- Create: `packages/nexus-http-auth/.gitkeep` for `src/` and `tests/` (so git tracks empty dirs initially)
- Modify: `composer.json` (root) — add autoload + autoload-dev entries
- Run: `composer dump-autoload` inside container

- [ ] **Step 1: Write `packages/nexus-http-auth/composer.json`**

```json
{
  "name": "nexus-actors/http-auth",
  "description": "Pluggable authentication and authorization for the Nexus HTTP stack.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.5",
    "lcobucci/jwt": "^5.0",
    "nexus-actors/http": "self.version",
    "psr/http-factory": "^1.1",
    "psr/http-message": "^2.0",
    "psr/http-server-middleware": "^1.0",
    "psr/log": "^3.0"
  },
  "require-dev": {
    "nexus-actors/http-ws": "self.version",
    "nexus-actors/http-toolkit": "self.version",
    "nyholm/psr7": "^1.8",
    "phpunit/phpunit": "^13.0"
  },
  "suggest": {
    "nexus-actors/http-ws": "Install when you want WebSocket auth — the same middleware works on the upgrade request.",
    "nexus-actors/logger": "Install for structured PSR-3 logs of auth decisions with MDC integration."
  },
  "autoload": { "psr-4": { "Monadial\\Nexus\\Http\\Auth\\": "src/" } },
  "autoload-dev": { "psr-4": { "Monadial\\Nexus\\Http\\Auth\\Tests\\": "tests/" } },
  "minimum-stability": "stable",
  "prefer-stable": true,
  "config": { "sort-packages": true }
}
```

- [ ] **Step 2: Create `src/` and `tests/` directories**

```bash
mkdir -p packages/nexus-http-auth/src packages/nexus-http-auth/tests/Unit packages/nexus-http-auth/tests/Integration packages/nexus-http-auth/tests/Support
```

- [ ] **Step 3: Patch root `composer.json` autoload entries**

In `composer.json`, find the `autoload.psr-4` block. Add this line right after the `Monadial\\Nexus\\Http\\Server\\Swoole\\Threads\\` entry (alphabetically sorted within the Http group):

```json
            "Monadial\\Nexus\\Http\\Toolkit\\": "packages/nexus-http-toolkit/src/",
            "Monadial\\Nexus\\Http\\Auth\\": "packages/nexus-http-auth/src/",
```

In `autoload-dev.psr-4`, add right after the corresponding Toolkit entry:

```json
            "Monadial\\Nexus\\Http\\Toolkit\\Tests\\": "packages/nexus-http-toolkit/tests/",
            "Monadial\\Nexus\\Http\\Auth\\Tests\\": "packages/nexus-http-auth/tests/",
```

- [ ] **Step 4: Regenerate autoload + verify**

```bash
docker compose exec php-fiber composer require --no-update lcobucci/jwt:^5.0 2>&1 | tail -3
docker compose exec php-fiber composer update lcobucci/jwt nexus-actors/http-auth -q 2>&1 | tail -5
docker compose exec php-fiber composer dump-autoload -q
```

Expected: no errors. `lcobucci/jwt` installed in vendor.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/composer.json composer.json composer.lock vendor
git -c commit.gpgsign=false commit -m "feat(http-auth): scaffold package + lcobucci/jwt^5 dep"
```

---

## Task 2: AuthChallenge value object

A tiny readonly value object representing the WWW-Authenticate challenge.

**Files:**
- Create: `packages/nexus-http-auth/src/AuthChallenge.php`
- Create: `packages/nexus-http-auth/tests/Unit/AuthChallengeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit;

use Monadial\Nexus\Http\Auth\AuthChallenge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthChallenge::class)]
final class AuthChallengeTest extends TestCase
{
    #[Test]
    public function formats_a_bearer_challenge_with_realm(): void
    {
        $challenge = new AuthChallenge('Bearer', 'api');

        self::assertSame('Bearer realm="api"', $challenge->toHeader());
    }

    #[Test]
    public function omits_realm_when_null(): void
    {
        $challenge = new AuthChallenge('Bearer');

        self::assertSame('Bearer', $challenge->toHeader());
    }

    #[Test]
    public function appends_error_parameter_when_set(): void
    {
        $challenge = new AuthChallenge('Bearer', 'api', 'invalid_token');

        self::assertSame('Bearer realm="api", error="invalid_token"', $challenge->toHeader());
    }

    #[Test]
    public function escapes_double_quotes_in_realm(): void
    {
        $challenge = new AuthChallenge('Bearer', 'pro"d');

        self::assertSame('Bearer realm="pro\\"d"', $challenge->toHeader());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/AuthChallengeTest.php
```

Expected: 4 errors — `Monadial\Nexus\Http\Auth\AuthChallenge` does not exist.

- [ ] **Step 3: Write the AuthChallenge class**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use function str_replace;

/**
 * @psalm-api
 *
 * RFC 7235 WWW-Authenticate challenge. Returned with 401 responses so the
 * client knows what credentials to present. realm and error are optional.
 *
 * Standard error codes (RFC 6750): "invalid_token", "insufficient_scope".
 */
final readonly class AuthChallenge
{
    public function __construct(
        public string $scheme,
        public ?string $realm = null,
        public ?string $error = null,
    ) {}

    public function toHeader(): string
    {
        $parts = [$this->scheme];

        if ($this->realm !== null) {
            $parts[0] = $this->scheme . ' realm="' . str_replace('"', '\\"', $this->realm) . '"';
        }

        if ($this->error !== null) {
            $parts[] = 'error="' . str_replace('"', '\\"', $this->error) . '"';
        }

        return implode(', ', $parts);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/AuthChallengeTest.php
```

Expected: `OK (4 tests, 4 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/src/AuthChallenge.php packages/nexus-http-auth/tests/Unit/AuthChallengeTest.php
git -c commit.gpgsign=false commit -m "feat(http-auth): AuthChallenge value object"
```

---

## Task 3: Principal + Authenticator + Authorizer + TokenExtractor interfaces

Four interfaces, no behavior — covered by integration tests later. Group into one task because each interface is just declarations.

**Files:**
- Create: `packages/nexus-http-auth/src/Principal.php`
- Create: `packages/nexus-http-auth/src/Authenticator.php`
- Create: `packages/nexus-http-auth/src/Authorizer.php`
- Create: `packages/nexus-http-auth/src/TokenExtractor.php`

- [ ] **Step 1: Write Principal interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

/**
 * @psalm-api
 *
 * The "who" of an authenticated request. Set by AuthenticationMiddleware
 * onto the PSR-7 request attribute "principal"; read by handlers via
 * #[FromPrincipal] or $req->getAttribute('principal').
 *
 * Implementations should be immutable readonly value objects. The default
 * SimplePrincipal covers 90% of cases; custom implementations let you carry
 * domain-specific identity (user objects, tenant ids, etc).
 */
interface Principal
{
    /**
     * Stable identifier for the principal — used for logging, audit, MDC.
     * Typically a user id, service account name, or "anonymous".
     */
    public function id(): string;

    /** @return list<string> */
    public function roles(): array;

    /** @return list<string> */
    public function scopes(): array;

    /** @return array<string, mixed> */
    public function claims(): array;

    public function hasRole(string $role): bool;

    public function hasScope(string $scope): bool;
}
```

- [ ] **Step 2: Write Authenticator interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Verifies request credentials and returns a Principal, or null for
 * anonymous. Implementations MUST swallow all credential validation
 * failures (bad signature, expired, malformed) and return null —
 * never throw on a bad token. Exceptions are reserved for configuration
 * errors (missing key, broken backend).
 *
 * The middleware never 401s based on null — that decision belongs to
 * AuthorizationMiddleware based on route attributes.
 */
interface Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal;
}
```

- [ ] **Step 3: Write Authorizer interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Custom policy hook for #[Authorize(MyPolicy::class)]. Returns true to
 * allow, false to deny (yielding 403). The request is provided so policies
 * can inspect path params, headers, or other request state.
 *
 * Implementations should be stateless — the framework may cache one
 * instance per handler.
 */
interface Authorizer
{
    public function authorize(Principal $principal, ServerRequestInterface $request): bool;
}
```

- [ ] **Step 4: Write TokenExtractor interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Pulls a credential string out of the request. Returns null when the
 * request carries no token in this extractor's expected location.
 *
 * Built-in extractors: BearerTokenExtractor (Authorization: Bearer ...),
 * HeaderTokenExtractor (custom header), CookieTokenExtractor (signed cookie).
 */
interface TokenExtractor
{
    public function extract(ServerRequestInterface $request): ?string;
}
```

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/src/Principal.php packages/nexus-http-auth/src/Authenticator.php packages/nexus-http-auth/src/Authorizer.php packages/nexus-http-auth/src/TokenExtractor.php
git -c commit.gpgsign=false commit -m "feat(http-auth): Principal/Authenticator/Authorizer/TokenExtractor interfaces"
```

(No tests for pure interfaces; they're exercised by the implementations in T6, T7, T12, T15.)

---

## Task 4: Exception hierarchy

**Files:**
- Create: `packages/nexus-http-auth/src/Exception/AuthException.php`
- Create: `packages/nexus-http-auth/src/Exception/Unauthenticated.php`
- Create: `packages/nexus-http-auth/src/Exception/Forbidden.php`
- Create: `packages/nexus-http-auth/src/Exception/AuthMiddlewareNotRegisteredException.php`
- Create: `packages/nexus-http-auth/src/Exception/InvalidAuthorizerException.php`

- [ ] **Step 1: Write AuthException abstract base**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Abstract base for auth-related exceptions. Sits under NexusException so
 * code that catches the project-wide base catches auth errors too.
 */
abstract class AuthException extends NexusException
{
}
```

- [ ] **Step 2: Write Unauthenticated**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * No valid credentials presented. Mapped to 401 by AuthorizationMiddleware.
 * Users can re-map via $app->onException(Unauthenticated::class, ...) to
 * customise the response shape.
 */
final class Unauthenticated extends AuthException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 3: Write Forbidden**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * Principal present but lacks required scope / role / policy. Mapped to
 * 403 by AuthorizationMiddleware. `missing` lists the constraints that
 * failed — empty array means "Authorize policy returned false" (opaque).
 *
 * `missing` is included in the 403 JSON body for client debugging. It
 * NEVER contains the Principal's actual claims — that's information
 * disclosure.
 */
final class Forbidden extends AuthException
{
    /** @param list<string> $missing */
    public function __construct(
        public readonly array $missing = [],
        string $message = 'Forbidden',
    ) {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Write AuthMiddlewareNotRegisteredException**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * Thrown when a handler requests #[FromPrincipal] but the request has no
 * 'principal' attribute — meaning AuthenticationMiddleware was never
 * registered. Bubbles to 500 with a diagnostic hint at request time.
 */
final class AuthMiddlewareNotRegisteredException extends AuthException
{
    public static function forHandler(string $handlerClass): self
    {
        return new self(
            "{$handlerClass} requested #[FromPrincipal] but no Principal was found on the request. "
            . 'Register AuthenticationMiddleware globally on your application: '
            . '$app->middleware(new AuthenticationMiddleware($authenticator)).',
        );
    }
}
```

- [ ] **Step 5: Write InvalidAuthorizerException**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

use Monadial\Nexus\Http\Auth\Authorizer;

/**
 * @psalm-api
 *
 * Thrown at compile time when #[Authorize(X::class)] references a class
 * that doesn't implement Authorizer. Fails the build, not the request.
 */
final class InvalidAuthorizerException extends AuthException
{
    public static function notAnAuthorizer(string $class): self
    {
        return new self(
            "#[Authorize({$class}::class)] — {$class} must implement " . Authorizer::class . '.',
        );
    }
}
```

- [ ] **Step 6: Verify autoload (no tests — exception classes are exercised by middleware tests)**

```bash
docker compose exec php-fiber composer dump-autoload -q
docker compose exec php-fiber php -r "var_dump(class_exists('Monadial\\Nexus\\Http\\Auth\\Exception\\Forbidden'));"
```

Expected: `bool(true)`.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http-auth/src/Exception/
git -c commit.gpgsign=false commit -m "feat(http-auth): exception hierarchy"
```

---

## Task 5: SimplePrincipal

**Files:**
- Create: `packages/nexus-http-auth/src/Principal/SimplePrincipal.php`
- Create: `packages/nexus-http-auth/tests/Unit/Principal/SimplePrincipalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Principal;

use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimplePrincipal::class)]
final class SimplePrincipalTest extends TestCase
{
    #[Test]
    public function exposes_id_roles_scopes_and_claims(): void
    {
        $p = new SimplePrincipal(
            id: 'user-42',
            roles: ['admin', 'staff'],
            scopes: ['orders.read', 'orders.write'],
            claims: ['email' => 'a@b.co'],
        );

        self::assertSame('user-42', $p->id());
        self::assertSame(['admin', 'staff'], $p->roles());
        self::assertSame(['orders.read', 'orders.write'], $p->scopes());
        self::assertSame(['email' => 'a@b.co'], $p->claims());
    }

    #[Test]
    public function has_role_returns_true_only_for_present_roles(): void
    {
        $p = new SimplePrincipal('u', roles: ['admin']);

        self::assertTrue($p->hasRole('admin'));
        self::assertFalse($p->hasRole('staff'));
    }

    #[Test]
    public function has_scope_returns_true_only_for_present_scopes(): void
    {
        $p = new SimplePrincipal('u', scopes: ['orders.read']);

        self::assertTrue($p->hasScope('orders.read'));
        self::assertFalse($p->hasScope('orders.write'));
    }

    #[Test]
    public function defaults_to_empty_collections(): void
    {
        $p = new SimplePrincipal('u');

        self::assertSame([], $p->roles());
        self::assertSame([], $p->scopes());
        self::assertSame([], $p->claims());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Principal/SimplePrincipalTest.php
```

Expected: 4 errors — `Monadial\Nexus\Http\Auth\Principal\SimplePrincipal` does not exist.

- [ ] **Step 3: Write SimplePrincipal**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Principal;

use Monadial\Nexus\Http\Auth\Principal;
use Override;

use function in_array;

/**
 * @psalm-api
 *
 * Default Principal implementation — readonly value object carrying id,
 * roles, scopes, and arbitrary claims. The default target for JwtAuthenticator's
 * claims-mapper.
 *
 * For domain-specific identity (User entity, tenant context), implement
 * Principal directly instead.
 */
final readonly class SimplePrincipal implements Principal
{
    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     * @param array<string, mixed> $claims
     */
    public function __construct(
        private string $id,
        private array $roles = [],
        private array $scopes = [],
        private array $claims = [],
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function roles(): array
    {
        return $this->roles;
    }

    #[Override]
    public function scopes(): array
    {
        return $this->scopes;
    }

    #[Override]
    public function claims(): array
    {
        return $this->claims;
    }

    #[Override]
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    #[Override]
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Principal/SimplePrincipalTest.php
```

Expected: `OK (4 tests, 12 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/src/Principal/ packages/nexus-http-auth/tests/Unit/Principal/
git -c commit.gpgsign=false commit -m "feat(http-auth): SimplePrincipal default Principal impl"
```

---

## Task 6: Token extractors (Bearer, Header, Cookie)

Three extractors share the same test shape: present/absent/wrong-shape.

**Files:**
- Create: `packages/nexus-http-auth/src/Extractor/BearerTokenExtractor.php`
- Create: `packages/nexus-http-auth/src/Extractor/HeaderTokenExtractor.php`
- Create: `packages/nexus-http-auth/src/Extractor/CookieTokenExtractor.php`
- Create: `packages/nexus-http-auth/tests/Unit/Extractor/BearerTokenExtractorTest.php`
- Create: `packages/nexus-http-auth/tests/Unit/Extractor/HeaderTokenExtractorTest.php`
- Create: `packages/nexus-http-auth/tests/Unit/Extractor/CookieTokenExtractorTest.php`

- [ ] **Step 1: Write BearerTokenExtractorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerTokenExtractor::class)]
final class BearerTokenExtractorTest extends TestCase
{
    #[Test]
    public function extracts_token_from_authorization_header(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer abc.def.ghi');

        self::assertSame('abc.def.ghi', (new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function returns_null_when_header_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull((new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function returns_null_when_scheme_is_not_bearer(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Basic dXNlcjpwYXNz');

        self::assertNull((new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function tolerates_extra_whitespace_after_bearer(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer   token-with-spaces');

        self::assertSame('token-with-spaces', (new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function case_insensitive_scheme_match(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'bearer token');

        self::assertSame('token', (new BearerTokenExtractor())->extract($req));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Extractor/BearerTokenExtractorTest.php
```

Expected: errors — class doesn't exist.

- [ ] **Step 3: Write BearerTokenExtractor**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

use function preg_match;

/**
 * @psalm-api
 *
 * Extracts the token from `Authorization: Bearer <token>`. The scheme
 * match is case-insensitive (per RFC 7235); the token itself preserves case.
 */
final class BearerTokenExtractor implements TokenExtractor
{
    private const string AUTH_HEADER_REGEX = '/^Bearer\s+(\S+)\s*$/i';

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            return null;
        }

        if (preg_match(self::AUTH_HEADER_REGEX, $header, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Extractor/BearerTokenExtractorTest.php
```

Expected: `OK (5 tests, 5 assertions)`.

- [ ] **Step 5: Write HeaderTokenExtractorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\HeaderTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderTokenExtractor::class)]
final class HeaderTokenExtractorTest extends TestCase
{
    #[Test]
    public function extracts_raw_header_value(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('X-Api-Key', 'k_live_abcdef');

        self::assertSame('k_live_abcdef', (new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }

    #[Test]
    public function returns_null_when_header_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull((new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }

    #[Test]
    public function returns_null_when_header_empty(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('X-Api-Key', '');

        self::assertNull((new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }
}
```

- [ ] **Step 6: Write HeaderTokenExtractor**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Reads the raw value of a configurable header. Useful for X-Api-Key /
 * X-Auth-Token style schemes where there's no scheme prefix.
 */
final class HeaderTokenExtractor implements TokenExtractor
{
    public function __construct(private readonly string $headerName) {}

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine($this->headerName);

        return $value === ''
            ? null
            : $value;
    }
}
```

- [ ] **Step 7: Write CookieTokenExtractorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\CookieTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CookieTokenExtractor::class)]
final class CookieTokenExtractorTest extends TestCase
{
    #[Test]
    public function reads_cookie_by_name(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withCookieParams(['session' => 'abc.def.ghi']);

        self::assertSame('abc.def.ghi', (new CookieTokenExtractor('session'))->extract($req));
    }

    #[Test]
    public function returns_null_when_cookie_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withCookieParams(['other' => 'x']);

        self::assertNull((new CookieTokenExtractor('session'))->extract($req));
    }
}
```

- [ ] **Step 8: Write CookieTokenExtractor**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;

/**
 * @psalm-api
 *
 * Reads a token from a cookie. The cookie value is treated as-is — if you
 * use signed cookies, verify the signature inside the Authenticator.
 */
final class CookieTokenExtractor implements TokenExtractor
{
    public function __construct(private readonly string $cookieName) {}

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();

        if (!isset($cookies[$this->cookieName])) {
            return null;
        }

        /** @var mixed $value */
        $value = $cookies[$this->cookieName];

        return is_string($value)
            ? $value
            : null;
    }
}
```

- [ ] **Step 9: Run all extractor tests**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Extractor/
```

Expected: `OK (10 tests, 10 assertions)`.

- [ ] **Step 10: Commit**

```bash
git add packages/nexus-http-auth/src/Extractor/ packages/nexus-http-auth/tests/Unit/Extractor/
git -c commit.gpgsign=false commit -m "feat(http-auth): BearerTokenExtractor + HeaderTokenExtractor + CookieTokenExtractor"
```

---

## Task 7: Auth attributes

Seven attributes, all are simple `#[Attribute]` classes carrying constructor arguments. No tests needed (declarative classes); they're covered by `AuthorizationMiddlewareTest` (T14).

**Files:**
- Create: `packages/nexus-http-auth/src/Attribute/FromPrincipal.php`
- Create: `packages/nexus-http-auth/src/Attribute/RequiresAuth.php`
- Create: `packages/nexus-http-auth/src/Attribute/RequiresScope.php`
- Create: `packages/nexus-http-auth/src/Attribute/RequiresAnyScope.php`
- Create: `packages/nexus-http-auth/src/Attribute/RequiresRole.php`
- Create: `packages/nexus-http-auth/src/Attribute/RequiresAnyRole.php`
- Create: `packages/nexus-http-auth/src/Attribute/Authorize.php`

- [ ] **Step 1: Write FromPrincipal**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Constructor parameter attribute — injects the Principal stamped by
 * AuthenticationMiddleware onto the request. Mirrors #[FromActor] /
 * #[FromService] / #[FromBody] in nexus-http.
 *
 * Recognized by patched nexus-http/HandlerResolver and
 * nexus-http-ws/HandlerInstantiator via class-string lookup, so this
 * package doesn't need to be imported by those.
 *
 *   public function __construct(
 *       #[FromPrincipal] private readonly Principal $principal,
 *   ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromPrincipal
{
}
```

- [ ] **Step 2: Write RequiresAuth**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Class-level attribute on handler classes. AuthorizationMiddleware
 * returns 401 if the request has no Principal.
 *
 *   #[RequiresAuth]
 *   final class MyHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAuth
{
}
```

- [ ] **Step 3: Write RequiresScope (all-of semantics)**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * All-of scope check. 403 if the Principal lacks ANY of the listed scopes.
 *
 *   #[RequiresScope('orders.read', 'orders.write')]
 *   final class CreateOrderHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresScope
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
```

- [ ] **Step 4: Write RequiresAnyScope (any-of semantics)**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Any-of scope check. 403 if the Principal lacks ALL of the listed scopes
 * (i.e. has none of them). Passes if at least one is present.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAnyScope
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
```

- [ ] **Step 5: Write RequiresRole + RequiresAnyRole**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * All-of role check. 403 if the Principal lacks ANY of the listed roles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresRole
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Any-of role check. Passes if the Principal has at least one of the listed roles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAnyRole
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
```

- [ ] **Step 6: Write Authorize**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;
use Monadial\Nexus\Http\Auth\Authorizer;

/**
 * @psalm-api
 *
 * Custom policy delegation. AuthorizationMiddleware resolves the named
 * class (via PSR-11 container or no-args construction) and calls
 * Authorizer::authorize(Principal, ServerRequest). 403 if it returns false.
 *
 * The referenced class MUST implement Authorizer. Validated at compile
 * time — InvalidAuthorizerException at boot if not.
 *
 *   #[Authorize(OrderOwnerPolicy::class)]
 *   final class ShowOrderHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Authorize
{
    /** @param class-string<Authorizer> $authorizer */
    public function __construct(public string $authorizer) {}
}
```

- [ ] **Step 7: Smoke test that all attribute classes exist**

```bash
docker compose exec php-fiber composer dump-autoload -q
docker compose exec php-fiber php -r "
foreach ([
    'FromPrincipal', 'RequiresAuth', 'RequiresScope', 'RequiresAnyScope',
    'RequiresRole', 'RequiresAnyRole', 'Authorize',
] as \$cls) {
    \$fq = 'Monadial\\\\Nexus\\\\Http\\\\Auth\\\\Attribute\\\\' . \$cls;
    if (!class_exists(\$fq)) { echo \"MISSING: \$fq\n\"; exit(1); }
}
echo \"all 7 attributes loaded\n\";
"
```

Expected: `all 7 attributes loaded`.

- [ ] **Step 8: Commit**

```bash
git add packages/nexus-http-auth/src/Attribute/
git -c commit.gpgsign=false commit -m "feat(http-auth): 7 auth attributes (FromPrincipal + RequiresAuth + scope/role variants + Authorize)"
```

---

## Task 8: nexus-http patch — `#[FromPrincipal]` in HandlerResolver

We patch `nexus-http` (NOT `nexus-http-auth`) to teach `HandlerResolver` about `#[FromPrincipal]`. We reference it by **FQCN string literal** to keep `nexus-http`'s dep graph free of `nexus-http-auth`.

**Files:**
- Modify: `packages/nexus-http/src/Handler/ParamMetadata.php` (add `KIND_FROM_PRINCIPAL`)
- Modify: `packages/nexus-http/src/Handler/HandlerResolver.php` (recognize attribute, read request attribute)
- Create: `packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php`

- [ ] **Step 1: Add the new ParamMetadata kind constant**

In `packages/nexus-http/src/Handler/ParamMetadata.php`, find the block of `KIND_*` constants. Add (alphabetically):

```php
    public const string KIND_FROM_PRINCIPAL = 'from_principal';
```

- [ ] **Step 2: Write the failing test**

Create `packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(HandlerResolver::class)]
final class HandlerResolverFromPrincipalTest extends TestCase
{
    #[Test]
    public function from_principal_param_reads_the_principal_request_attribute(): void
    {
        $resolver = new HandlerResolver(new ResolvedActorTable([], []), null);

        $resolved = $resolver->resolve(PrincipalHandler::class);

        $factory = new Psr17Factory();
        $principal = new \stdClass();
        $principal->id = 'tomas';
        $request = $factory->createServerRequest('GET', '/me')->withAttribute('principal', $principal);

        $scope = new PerRequestActorScope(new ResolvedActorTable([], []));

        try {
            $response = ($resolved->invoke)($request, $scope, []);
        } finally {
            $scope->dispose();
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('tomas', (string) $response->getBody());
    }
}

final class PrincipalHandler
{
    public function __construct(
        #[\Monadial\Nexus\Http\Auth\Attribute\FromPrincipal] private readonly \stdClass $principal,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        return new \Nyholm\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['id' => $this->principal->id]),
        );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php
```

Expected: error — `Cannot resolve … no #[FromService] attribute and no PSR-11 container binding`.

- [ ] **Step 4: Patch HandlerResolver to recognize #[FromPrincipal]**

In `packages/nexus-http/src/Handler/HandlerResolver.php`, in `describeParams()`, between the `FromService` check and the `if ($type === ServerRequestInterface::class)` check, add:

```php
            $fromPrincipal = $p->getAttributes('Monadial\\Nexus\\Http\\Auth\\Attribute\\FromPrincipal');

            if ($fromPrincipal !== []) {
                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_PRINCIPAL);

                continue;
            }
```

And in `buildArgs()`, in the `match ($p->kind)` block, add a new case:

```php
                ParamMetadata::KIND_FROM_PRINCIPAL => $r->getAttribute('principal')
                    ?? throw new \LogicException(
                        'Handler requested #[FromPrincipal] but no Principal on request — '
                        . 'register AuthenticationMiddleware globally.',
                    ),
```

And in `instantiate()` (the constructor-side match), add the same case:

```php
                ParamMetadata::KIND_FROM_PRINCIPAL => throw new \LogicException(
                    "Handler {$class} requested #[FromPrincipal] in its constructor — "
                    . 'principal is per-request, declare it on the invoke method instead.',
                ),
```

Wait — re-reading the spec: `#[FromPrincipal]` is on the **constructor**. We need to defer principal resolution to **request time** (the invoke phase), like how `#[FromActor]` for per-request actors works. The cleanest approach: detect `KIND_FROM_PRINCIPAL` in `paramsNeedScope()` to trigger scope, and resolve in `instantiate()` at request time by reading the request attribute that's threaded through `PerRequestActorScope`.

But the current `instantiate()` runs ONCE at handler resolve (boot time), not per request. Looking at `HandlerResolver::resolveClassMethod`: the instance is constructed once, then `$invoke` closures over it. So injecting principal into the constructor isn't possible because the constructor only runs once.

**Revised approach:** `#[FromPrincipal]` is recognised on the **invoke method** parameters (not the constructor). The spec's wiring example puts the attribute on the constructor; we adjust to put it on the invoke method. Update the spec → wiring example shows invoke-level injection.

Update the patch:

In `describeParams()`, the FromPrincipal block goes between FromService and the type-based checks. The "instantiate" branch above is NOT needed — constructor-time principal isn't supported. If `KIND_FROM_PRINCIPAL` shows up in ctorParams (i.e. someone put `#[FromPrincipal]` on the constructor), throw a clear error:

```php
            $fromPrincipal = $p->getAttributes('Monadial\\Nexus\\Http\\Auth\\Attribute\\FromPrincipal');

            if ($fromPrincipal !== []) {
                if ($inConstructor) {
                    throw new LogicException(
                        "Cannot resolve {$owner}::__construct(\${$name}) via #[FromPrincipal] — "
                        . 'principal is per-request; declare it on __invoke() instead.',
                    );
                }

                $out[] = new ParamMetadata($name, $type, ParamMetadata::KIND_FROM_PRINCIPAL);

                continue;
            }
```

And `buildArgs()` (used by invoke, not constructor) gets:

```php
                ParamMetadata::KIND_FROM_PRINCIPAL => $r->getAttribute('principal')
                    ?? throw new LogicException(
                        'Handler requested #[FromPrincipal] but no Principal on request — '
                        . 'register AuthenticationMiddleware globally.',
                    ),
```

(No change needed to `instantiate()`.)

- [ ] **Step 5: Update the failing test to put #[FromPrincipal] on invoke, not constructor**

Replace the test handler class with:

```php
final class PrincipalHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[\Monadial\Nexus\Http\Auth\Attribute\FromPrincipal] \stdClass $principal,
    ): ResponseInterface {
        return new \Nyholm\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['id' => $principal->id]),
        );
    }
}
```

- [ ] **Step 6: Re-run the test**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php
```

Expected: `OK (1 test, 2 assertions)`.

- [ ] **Step 7: Run the existing nexus-http test suite to verify no regressions**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add packages/nexus-http/src/Handler/ParamMetadata.php packages/nexus-http/src/Handler/HandlerResolver.php packages/nexus-http/tests/Unit/Handler/HandlerResolverFromPrincipalTest.php
git -c commit.gpgsign=false commit -m "feat(http): recognize #[FromPrincipal] on __invoke params

Adds ParamMetadata::KIND_FROM_PRINCIPAL and reads the 'principal' request
attribute when present. Throws if the attribute appears on a constructor
(constructor runs once at boot, principal is per-request)."
```

---

## Task 9: nexus-http patch — handler class on request attribute

`AuthorizationMiddleware` needs to know which handler class matched in order to scan its attributes. We patch `RouterMiddleware` to set `'_resolvedHandlerClass'`.

**Files:**
- Modify: `packages/nexus-http/src/Middleware/RouterMiddleware.php`
- Create: `packages/nexus-http/tests/Unit/Middleware/RouterMiddlewareHandlerClassAttributeTest.php`

- [ ] **Step 1: Read the existing RouterMiddleware to find the right insertion point**

```bash
docker compose exec php-fiber grep -n "match\|resolved\|route->handler\|getAttribute" packages/nexus-http/src/Middleware/RouterMiddleware.php | head
```

The route handler is on `$route->handler` (string class-name or Closure). We want to add `$request = $request->withAttribute('_resolvedHandlerClass', $route->handler)` when the handler is a string.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\App\HttpApp;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(\Monadial\Nexus\Http\Middleware\RouterMiddleware::class)]
final class RouterMiddlewareHandlerClassAttributeTest extends TestCase
{
    #[Test]
    public function router_stamps_handler_class_on_request(): void
    {
        $captured = new class implements MiddlewareInterface {
            public ?string $handlerClass = null;

            #[Override]
            public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
            {
                /** @var mixed $hc */
                $hc = $req->getAttribute('_resolvedHandlerClass');
                $this->handlerClass = is_string($hc) ? $hc : null;

                return $next->handle($req);
            }
        };

        $app = HttpApp::create(/* needs ActorSystem; see existing tests for the right wiring */)
            ->middleware($captured)
            ->get('/test', ClassHandler::class)
            ->compile();

        $factory = new Psr17Factory();
        $app->handle($factory->createServerRequest('GET', '/test'));

        self::assertSame(ClassHandler::class, $captured->handlerClass);
    }
}

final class ClassHandler
{
    public function __invoke(): ResponseInterface
    {
        return new \Nyholm\Psr7\Response(200);
    }
}
```

Note: the `HttpApp::create()` call needs an `ActorSystem`. Check existing `RouterMiddlewareTest`-style tests in `packages/nexus-http/tests/Unit/Middleware/` for the established pattern, and copy it.

- [ ] **Step 3: Run the test, expect failure (attribute not set)**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Middleware/RouterMiddlewareHandlerClassAttributeTest.php
```

Expected: failure — `$captured->handlerClass` is `null` (or test errors due to wiring; fix wiring per established patterns first).

- [ ] **Step 4: Patch RouterMiddleware**

In `packages/nexus-http/src/Middleware/RouterMiddleware.php`, find where the matched route is processed (after `$result` from the dispatcher). Add right before the pipeline invocation:

```php
        if (is_string($route->handler)) {
            $request = $request->withAttribute('_resolvedHandlerClass', $route->handler);
        }
```

Place this AFTER `$request` has had path-param attributes set but BEFORE middleware dispatch. (Inspect the existing code to confirm the exact line.)

- [ ] **Step 5: Re-run the test**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit/Middleware/RouterMiddlewareHandlerClassAttributeTest.php
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 6: Run the rest of nexus-http to verify no regressions**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http/tests/
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http/src/Middleware/RouterMiddleware.php packages/nexus-http/tests/Unit/Middleware/RouterMiddlewareHandlerClassAttributeTest.php
git -c commit.gpgsign=false commit -m "feat(http): stamp _resolvedHandlerClass on request after route match

Enables downstream middleware (e.g. AuthorizationMiddleware) to scan
attributes on the matched handler class without re-running route
matching. Set only when the handler is a class-string; closures don't
get a class name."
```

---

## Task 10: nexus-http-ws patch — `#[FromPrincipal]` in HandlerInstantiator

Mirror of Task 8 but for `HandlerInstantiator` in `nexus-http-ws`, which has a different DI surface.

**Files:**
- Modify: `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php`
- Create: `packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php`

- [ ] **Step 1: Read the existing HandlerInstantiator to find the param-resolution method**

```bash
docker compose exec php-fiber grep -n "FromContext\|resolveParam\|getAttributes" packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php
```

There's a `resolveParam()` method that checks `FromContext`. We need to add a `FromPrincipal` branch right after it.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

#[CoversClass(HandlerInstantiator::class)]
final class HandlerInstantiatorFromPrincipalTest extends TestCase
{
    #[Test]
    public function from_principal_constructor_param_resolves_principal_from_request_attribute(): void
    {
        $principal = new \stdClass();
        $principal->id = 'tomas';

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/ws/echo')
            ->withAttribute('principal', $principal);

        $ctx = $this->createMock(WebSocketContext::class);
        $ctx->method('request')->willReturn($request);
        $ctx->method('id')->willReturn(42);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed { throw new class extends RuntimeException implements NotFoundExceptionInterface {}; }
            public function has(string $id): bool { return false; }
        };

        $handler = (new HandlerInstantiator($container))
            ->instantiate(PrincipalAwareHandler::class, $ctx);

        self::assertInstanceOf(PrincipalAwareHandler::class, $handler);
        self::assertSame('tomas', $handler->principalId);
    }
}

final class PrincipalAwareHandler extends WebSocketHandler
{
    public string $principalId;

    public function __construct(
        #[\Monadial\Nexus\Http\Auth\Attribute\FromPrincipal] \stdClass $principal,
    ) {
        $this->principalId = (string) $principal->id;
    }

    #[Override]
    public function onMessage(WebSocketFrame $frame): void {}
}
```

- [ ] **Step 3: Run, expect failure**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
```

Expected: failure — `#[FromPrincipal]` not recognised.

- [ ] **Step 4: Patch HandlerInstantiator::resolveParam()**

In `packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php`, in `resolveParam()`, right after the `FromContext` check, add:

```php
        if (count($param->getAttributes('Monadial\\Nexus\\Http\\Auth\\Attribute\\FromPrincipal')) > 0) {
            /** @var mixed $principal */
            $principal = $ctx->request()->getAttribute('principal');

            if ($principal === null) {
                throw new RuntimeException(
                    "WebSocketHandler {$handlerClass} requested #[FromPrincipal] but no Principal "
                    . 'on request — register AuthenticationMiddleware globally so the upgrade '
                    . 'request gets a Principal stamped before reaching the WS dispatcher.',
                );
            }

            return $principal;
        }
```

(Use the existing error-style and logger calls in the file as templates.)

- [ ] **Step 5: Re-run, expect green**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
```

Expected: `OK (1 test, 2 assertions)`.

- [ ] **Step 6: Run full nexus-http-ws test suite**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-ws/tests/
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-http-ws/src/WebSocket/HandlerInstantiator.php packages/nexus-http-ws/tests/Unit/WebSocket/HandlerInstantiatorFromPrincipalTest.php
git -c commit.gpgsign=false commit -m "feat(http-ws): recognize #[FromPrincipal] on WebSocketHandler constructors

Mirrors the equivalent change in nexus-http. References the attribute
class by FQCN string literal so nexus-http-ws gains no hard dep on
nexus-http-auth."
```

---

## Task 11: StaticTokenAuthenticator + ChainAuthenticator

Two trivial authenticators in one task — they share the test shape.

**Files:**
- Create: `packages/nexus-http-auth/src/Authenticator/StaticTokenAuthenticator.php`
- Create: `packages/nexus-http-auth/src/Authenticator/ChainAuthenticator.php`
- Create: `packages/nexus-http-auth/tests/Unit/Authenticator/StaticTokenAuthenticatorTest.php`
- Create: `packages/nexus-http-auth/tests/Unit/Authenticator/ChainAuthenticatorTest.php`

- [ ] **Step 1: Write StaticTokenAuthenticatorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticTokenAuthenticator::class)]
final class StaticTokenAuthenticatorTest extends TestCase
{
    #[Test]
    public function returns_principal_for_known_token(): void
    {
        $alice = new SimplePrincipal('alice');
        $auth = new StaticTokenAuthenticator(['k_alice' => $alice], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer k_alice');

        self::assertSame($alice, $auth->authenticate($req));
    }

    #[Test]
    public function returns_null_for_unknown_token(): void
    {
        $auth = new StaticTokenAuthenticator(['k_alice' => new SimplePrincipal('alice')], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer k_bob');

        self::assertNull($auth->authenticate($req));
    }

    #[Test]
    public function returns_null_when_extractor_finds_no_token(): void
    {
        $auth = new StaticTokenAuthenticator(['k_alice' => new SimplePrincipal('alice')], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($auth->authenticate($req));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/StaticTokenAuthenticatorTest.php
```

Expected: error — class doesn't exist.

- [ ] **Step 3: Write StaticTokenAuthenticator**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Map<token, Principal>. Use in tests and fixtures — never in production.
 * No cryptographic verification, just a string-keyed lookup.
 *
 *   $auth = new StaticTokenAuthenticator([
 *       'k_alice' => new SimplePrincipal('alice', scopes: ['orders.read']),
 *       'k_admin' => new SimplePrincipal('admin', roles: ['admin']),
 *   ]);
 */
final readonly class StaticTokenAuthenticator implements Authenticator
{
    private TokenExtractor $extractor;

    /** @param array<string, Principal> $tokenToPrincipal */
    public function __construct(
        private array $tokenToPrincipal,
        ?TokenExtractor $extractor = null,
    ) {
        $this->extractor = $extractor ?? new BearerTokenExtractor();
    }

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $token = $this->extractor->extract($request);

        if ($token === null) {
            return null;
        }

        return $this->tokenToPrincipal[$token] ?? null;
    }
}
```

- [ ] **Step 4: Re-run, expect green**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/StaticTokenAuthenticatorTest.php
```

Expected: `OK (3 tests, 3 assertions)`.

- [ ] **Step 5: Write ChainAuthenticatorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Authenticator\ChainAuthenticator;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[CoversClass(ChainAuthenticator::class)]
final class ChainAuthenticatorTest extends TestCase
{
    #[Test]
    public function returns_first_non_null_principal(): void
    {
        $alice = new SimplePrincipal('alice');
        $bob = new SimplePrincipal('bob');

        $chain = new ChainAuthenticator([
            new StubAuthenticator(null),
            new StubAuthenticator($alice),
            new StubAuthenticator($bob),
        ]);

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertSame($alice, $chain->authenticate($req));
    }

    #[Test]
    public function returns_null_when_all_return_null(): void
    {
        $chain = new ChainAuthenticator([new StubAuthenticator(null), new StubAuthenticator(null)]);

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($chain->authenticate($req));
    }

    #[Test]
    public function empty_chain_returns_null(): void
    {
        $chain = new ChainAuthenticator([]);

        self::assertNull($chain->authenticate((new Psr17Factory())->createServerRequest('GET', '/')));
    }

    #[Test]
    public function exceptions_from_inner_authenticators_propagate(): void
    {
        $chain = new ChainAuthenticator([
            new ThrowingAuthenticator(new RuntimeException('backend down')),
            new StubAuthenticator(new SimplePrincipal('alice')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backend down');

        $chain->authenticate((new Psr17Factory())->createServerRequest('GET', '/'));
    }
}

final readonly class StubAuthenticator implements Authenticator
{
    public function __construct(private ?Principal $principal) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return $this->principal;
    }
}

final readonly class ThrowingAuthenticator implements Authenticator
{
    public function __construct(private \Throwable $exception) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        throw $this->exception;
    }
}
```

- [ ] **Step 6: Run, expect failure (ChainAuthenticator doesn't exist)**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/ChainAuthenticatorTest.php
```

Expected: errors.

- [ ] **Step 7: Write ChainAuthenticator**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Principal;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Tries authenticators in order, first non-null result wins. Exceptions
 * from inner authenticators propagate (configuration errors should not
 * be silenced).
 *
 *   new ChainAuthenticator([
 *       new JwtAuthenticator(...),     // primary
 *       new StaticTokenAuthenticator($devTokens),  // fallback for tests/dev
 *   ]);
 */
final readonly class ChainAuthenticator implements Authenticator
{
    /** @param list<Authenticator> $authenticators */
    public function __construct(private array $authenticators) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        foreach ($this->authenticators as $auth) {
            $principal = $auth->authenticate($request);

            if ($principal !== null) {
                return $principal;
            }
        }

        return null;
    }
}
```

- [ ] **Step 8: Re-run all authenticator tests**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/
```

Expected: `OK (7 tests, 7 assertions)`.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-http-auth/src/Authenticator/ packages/nexus-http-auth/tests/Unit/Authenticator/
git -c commit.gpgsign=false commit -m "feat(http-auth): StaticTokenAuthenticator + ChainAuthenticator"
```

---

## Task 12: AuthenticationMiddleware

The middleware that stamps the Principal onto the request. Never 401s on its own.

**Files:**
- Create: `packages/nexus-http-auth/src/Middleware/AuthenticationMiddleware.php`
- Create: `packages/nexus-http-auth/tests/Unit/Middleware/AuthenticationMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AuthenticationMiddleware::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    #[Test]
    public function stamps_principal_when_authenticator_returns_one(): void
    {
        $alice = new SimplePrincipal('alice');
        $captured = new CapturingHandler();

        $mw = new AuthenticationMiddleware(new StubAuthenticator($alice));
        $mw->process((new Psr17Factory())->createServerRequest('GET', '/'), $captured);

        self::assertSame($alice, $captured->capturedRequest?->getAttribute('principal'));
    }

    #[Test]
    public function leaves_request_unchanged_when_authenticator_returns_null(): void
    {
        $captured = new CapturingHandler();

        $mw = new AuthenticationMiddleware(new StubAuthenticator(null));
        $mw->process((new Psr17Factory())->createServerRequest('GET', '/'), $captured);

        self::assertNull($captured->capturedRequest?->getAttribute('principal'));
    }
}

final readonly class StubAuthenticator implements Authenticator
{
    public function __construct(private ?Principal $principal) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return $this->principal;
    }
}

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $capturedRequest = null;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->capturedRequest = $request;

        return new Response(200);
    }
}
```

- [ ] **Step 2: Run, expect failure (class doesn't exist)**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Middleware/AuthenticationMiddlewareTest.php
```

- [ ] **Step 3: Write AuthenticationMiddleware**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Middleware;

use Monadial\Nexus\Http\Auth\Authenticator;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Runs an Authenticator and stamps the resulting Principal onto the
 * 'principal' request attribute. Never 401s — anonymous requests flow
 * through unchanged. AuthorizationMiddleware (per-route) is responsible
 * for the 401/403 decision based on route attributes.
 *
 * Register globally:
 *
 *   $app->middleware(new AuthenticationMiddleware($authenticator, $logger))
 *       ->get('/health', static fn() => Response::ok())          // public
 *       ->get('/orders', OrderListHandler::class);                // RequiresAuth on the class
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $principal = $this->authenticator->authenticate($request);

        if ($principal !== null) {
            $this->logger->debug('auth.principal.stamped', ['principalId' => $principal->id()]);
            $request = $request->withAttribute('principal', $principal);
        } else {
            $this->logger->debug('auth.anonymous');
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Re-run, expect green**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Middleware/AuthenticationMiddlewareTest.php
```

Expected: `OK (2 tests, 2 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/src/Middleware/AuthenticationMiddleware.php packages/nexus-http-auth/tests/Unit/Middleware/AuthenticationMiddlewareTest.php
git -c commit.gpgsign=false commit -m "feat(http-auth): AuthenticationMiddleware stamps Principal, never 401s"
```

---

## Task 13: AuthorizationMiddleware

This is the workhorse. Reads the matched handler class from `'_resolvedHandlerClass'` (set by `RouterMiddleware`), reflects on it to find `#[Requires*]` / `#[Authorize]` attributes, decides 401 / 403 / pass.

**Files:**
- Create: `packages/nexus-http-auth/src/Middleware/AuthorizationMiddleware.php`
- Create: `packages/nexus-http-auth/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\AuthChallenge;
use Monadial\Nexus\Http\Auth\Authorizer;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function json_decode;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    #[Test]
    public function passes_through_when_handler_has_no_auth_attributes(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()->withAttribute('_resolvedHandlerClass', PublicHandler::class);
        $response = $mw->process($req, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($next->wasCalled);
    }

    #[Test]
    public function returns_401_when_requires_auth_but_no_principal(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()->withAttribute('_resolvedHandlerClass', AuthRequiredHandler::class);
        $response = $mw->process($req, $next);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($next->wasCalled);
    }

    #[Test]
    public function returns_200_when_requires_auth_and_principal_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AuthRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice'));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());
    }

    #[Test]
    public function requires_scope_403_when_missing_any_required_scope(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', ScopeRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['orders.read']));

        $response = $mw->process($req, $next);

        self::assertSame(403, $response->getStatusCode());
        /** @var array{error: string, missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('forbidden', $body['error']);
        self::assertSame(['orders.write'], $body['missing']);
    }

    #[Test]
    public function requires_any_scope_200_when_any_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AnyScopeHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['orders.read']));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());
    }

    #[Test]
    public function requires_any_scope_403_when_none_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AnyScopeHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['unrelated']));

        $response = $mw->process($req, $next);

        self::assertSame(403, $response->getStatusCode());
        /** @var array{missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['orders.read', 'orders.write'], $body['missing']);
    }

    #[Test]
    public function requires_role_works_analogously_to_scope(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $passing = $this->req()
            ->withAttribute('_resolvedHandlerClass', AdminHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', roles: ['admin']));
        self::assertSame(200, $mw->process($passing, $next)->getStatusCode());

        $failing = $this->req()
            ->withAttribute('_resolvedHandlerClass', AdminHandler::class)
            ->withAttribute('principal', new SimplePrincipal('bob', roles: ['guest']));
        self::assertSame(403, $mw->process($failing, $next)->getStatusCode());
    }

    #[Test]
    public function authorize_attribute_delegates_to_named_policy(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', PolicyHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice'));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());

        $denied = $this->req()
            ->withAttribute('_resolvedHandlerClass', PolicyHandler::class)
            ->withAttribute('principal', new SimplePrincipal('bob'));

        $response = $mw->process($denied, $next);
        self::assertSame(403, $response->getStatusCode());
        /** @var array{missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['missing'], 'Authorize failures have empty missing[] (opaque)');
    }

    #[Test]
    public function never_discloses_principal_claims_in_forbidden_body(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', ScopeRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['some.private.scope']));

        $response = $mw->process($req, $next);
        $body = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('some.private.scope', $body);
        self::assertStringNotContainsString('alice', $body);
    }

    private function req(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/test');
    }
}

final class PublicHandler {}

#[RequiresAuth]
final class AuthRequiredHandler {}

#[RequiresScope('orders.read', 'orders.write')]
final class ScopeRequiredHandler {}

#[RequiresAnyScope('orders.read', 'orders.write')]
final class AnyScopeHandler {}

#[RequiresRole('admin')]
final class AdminHandler {}

#[Authorize(AlicePolicy::class)]
final class PolicyHandler {}

final class AlicePolicy implements Authorizer
{
    #[Override]
    public function authorize(Principal $principal, ServerRequestInterface $request): bool
    {
        return $principal->id() === 'alice';
    }
}

final class OkHandler implements RequestHandlerInterface
{
    public bool $wasCalled = false;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->wasCalled = true;

        return new Response(200);
    }
}
```

- [ ] **Step 2: Run, expect failure (class doesn't exist)**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Middleware/AuthorizationMiddlewareTest.php
```

- [ ] **Step 3: Write AuthorizationMiddleware**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Middleware;

use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\AuthChallenge;
use Monadial\Nexus\Http\Auth\Authorizer;
use Monadial\Nexus\Http\Auth\Exception\InvalidAuthorizerException;
use Monadial\Nexus\Http\Auth\Principal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

use function array_diff;
use function array_intersect;
use function array_values;
use function is_string;
use function json_encode;

/**
 * @psalm-api
 *
 * Reads the handler class set by RouterMiddleware on the
 * '_resolvedHandlerClass' request attribute. Reflects on it once (cached
 * by class name) for #[RequiresAuth] / #[RequiresScope] / #[RequiresRole]
 * / #[Authorize] attributes.
 *
 * Decisions:
 *   - no attributes on the class → pass through
 *   - any attribute + no Principal → 401 + WWW-Authenticate
 *   - Principal lacks required scope/role → 403 with `missing` list
 *   - Authorize policy returns false → 403 with empty `missing`
 *
 * Register globally AFTER AuthenticationMiddleware:
 *
 *   $app->middleware(new AuthenticationMiddleware($authenticator))
 *       ->middleware(new AuthorizationMiddleware());
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    /** @var array<class-string, AuthMetadata> */
    private array $metaCache = [];

    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct(
        private readonly AuthChallenge $challenge = new AuthChallenge('Bearer', 'api'),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? new Psr17Factory();
    }

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        /** @var mixed $handlerClass */
        $handlerClass = $request->getAttribute('_resolvedHandlerClass');

        if (!is_string($handlerClass) || $handlerClass === '') {
            return $handler->handle($request);
        }

        /** @var class-string $handlerClass */
        $meta = $this->metadata($handlerClass);

        if (!$meta->hasAnyAttribute()) {
            return $handler->handle($request);
        }

        /** @var mixed $principalAttr */
        $principalAttr = $request->getAttribute('principal');

        if (!$principalAttr instanceof Principal) {
            $this->logger->info('auth.unauthenticated', ['handler' => $handlerClass]);

            return $this->unauthorized();
        }

        $missing = $this->checkAttributes($meta, $principalAttr, $request);

        if ($missing !== null) {
            $this->logger->info('auth.forbidden', [
                'handler'     => $handlerClass,
                'principalId' => $principalAttr->id(),
                'missing'     => $missing,
            ]);

            return $this->forbidden($missing);
        }

        return $handler->handle($request);
    }

    /**
     * @return list<string>|null  null = allowed, list = denied with missing constraints
     */
    private function checkAttributes(
        AuthMetadata $meta,
        Principal $principal,
        ServerRequestInterface $request,
    ): ?array {
        foreach ($meta->requiresScope as $required) {
            $missing = array_values(array_diff($required, $principal->scopes()));

            if ($missing !== []) {
                return $missing;
            }
        }

        foreach ($meta->requiresAnyScope as $anyOf) {
            if (array_intersect($anyOf, $principal->scopes()) === []) {
                return array_values($anyOf);
            }
        }

        foreach ($meta->requiresRole as $required) {
            $missing = array_values(array_diff($required, $principal->roles()));

            if ($missing !== []) {
                return $missing;
            }
        }

        foreach ($meta->requiresAnyRole as $anyOf) {
            if (array_intersect($anyOf, $principal->roles()) === []) {
                return array_values($anyOf);
            }
        }

        foreach ($meta->authorize as $authorizerClass) {
            if (!is_subclass_of($authorizerClass, Authorizer::class)) {
                throw InvalidAuthorizerException::notAnAuthorizer($authorizerClass);
            }

            /** @var Authorizer $authorizer */
            $authorizer = new $authorizerClass();

            if (!$authorizer->authorize($principal, $request)) {
                return [];
            }
        }

        return null;
    }

    private function forbidden(array $missing): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(403, 'Forbidden')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                (new Psr17Factory())->createStream(
                    (string) json_encode(['error' => 'forbidden', 'missing' => array_values($missing)]),
                ),
            );
    }

    /** @param class-string $handlerClass */
    private function metadata(string $handlerClass): AuthMetadata
    {
        if (isset($this->metaCache[$handlerClass])) {
            return $this->metaCache[$handlerClass];
        }

        $ref = new ReflectionClass($handlerClass);

        $requiresAuth = count($ref->getAttributes(RequiresAuth::class)) > 0;
        $requiresScope = [];
        $requiresAnyScope = [];
        $requiresRole = [];
        $requiresAnyRole = [];
        $authorize = [];

        foreach ($ref->getAttributes(RequiresScope::class) as $a) {
            $requiresScope[] = $a->newInstance()->scopes;
        }

        foreach ($ref->getAttributes(RequiresAnyScope::class) as $a) {
            $requiresAnyScope[] = $a->newInstance()->scopes;
        }

        foreach ($ref->getAttributes(RequiresRole::class) as $a) {
            $requiresRole[] = $a->newInstance()->roles;
        }

        foreach ($ref->getAttributes(RequiresAnyRole::class) as $a) {
            $requiresAnyRole[] = $a->newInstance()->roles;
        }

        foreach ($ref->getAttributes(Authorize::class) as $a) {
            $authorize[] = $a->newInstance()->authorizer;
        }

        return $this->metaCache[$handlerClass] = new AuthMetadata(
            $requiresAuth,
            $requiresScope,
            $requiresAnyScope,
            $requiresRole,
            $requiresAnyRole,
            $authorize,
        );
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(401, 'Unauthorized')
            ->withHeader('WWW-Authenticate', $this->challenge->toHeader())
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                (new Psr17Factory())->createStream(
                    (string) json_encode(['error' => 'unauthenticated']),
                ),
            );
    }
}

/**
 * @internal
 */
final readonly class AuthMetadata
{
    /**
     * @param list<list<string>>          $requiresScope    each inner list = ALL required
     * @param list<list<string>>          $requiresAnyScope each inner list = ANY required
     * @param list<list<string>>          $requiresRole
     * @param list<list<string>>          $requiresAnyRole
     * @param list<class-string>          $authorize
     */
    public function __construct(
        public bool $requiresAuth,
        public array $requiresScope,
        public array $requiresAnyScope,
        public array $requiresRole,
        public array $requiresAnyRole,
        public array $authorize,
    ) {}

    public function hasAnyAttribute(): bool
    {
        return $this->requiresAuth
            || $this->requiresScope !== []
            || $this->requiresAnyScope !== []
            || $this->requiresRole !== []
            || $this->requiresAnyRole !== []
            || $this->authorize !== [];
    }
}
```

- [ ] **Step 4: Re-run, expect green**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Middleware/AuthorizationMiddlewareTest.php
```

Expected: `OK (9 tests, 24+ assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/src/Middleware/AuthorizationMiddleware.php packages/nexus-http-auth/tests/Unit/Middleware/AuthorizationMiddlewareTest.php
git -c commit.gpgsign=false commit -m "feat(http-auth): AuthorizationMiddleware enforces auth attributes

Reflects on the matched handler class (from _resolvedHandlerClass)
and decides 401/403/pass. Cached by class name. Never discloses
Principal claims in the 403 body — missing[] lists only the failed
constraints."
```

---

## Task 14: JwtAuthenticator

The flagship authenticator. Wraps `lcobucci/jwt` for verification and delegates Principal mapping to a closure.

**Files:**
- Create: `packages/nexus-http-auth/src/Authenticator/JwtAuthenticator.php`
- Create: `packages/nexus-http-auth/tests/Unit/Authenticator/JwtAuthenticatorTest.php`
- Create: `packages/nexus-http-auth/tests/Support/Fixtures.php`

- [ ] **Step 1: Write Fixtures helper**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Principal;

/**
 * @psalm-api
 *
 * Test fixtures shared between JwtAuthenticatorTest and Integration tests.
 * Provides a stable HS256 keypair and a token-builder factory.
 */
final class Fixtures
{
    public static function hs256Config(): Configuration
    {
        return Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText('test-secret-at-least-256-bits-aaaaaaaaaaaa'),
        );
    }

    public static function tokenFor(
        Principal $principal,
        ?DateInterval $expiresIn = null,
        ?string $issuer = null,
        ?string $audience = null,
    ): Plain {
        $config = self::hs256Config();
        $now = new DateTimeImmutable();
        $exp = $expiresIn === null
            ? $now->modify('+1 hour')
            : $now->add($expiresIn);

        $builder = $config->builder()
            ->relatedTo($principal->id())
            ->issuedAt($now)
            ->expiresAt($exp)
            ->withClaim('roles', $principal->roles())
            ->withClaim('scope', implode(' ', $principal->scopes()));

        if ($issuer !== null) {
            $builder = $builder->issuedBy($issuer);
        }

        if ($audience !== null) {
            $builder = $builder->permittedFor($audience);
        }

        /** @var Plain */
        return $builder->getToken($config->signer(), $config->signingKey());
    }
}
```

- [ ] **Step 2: Write JwtAuthenticatorTest**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use DateInterval;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Tests\Support\Fixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function explode;
use function preg_replace;

#[CoversClass(JwtAuthenticator::class)]
final class JwtAuthenticatorTest extends TestCase
{
    #[Test]
    public function valid_token_yields_principal(): void
    {
        $alice = new SimplePrincipal('alice', scopes: ['orders.read', 'orders.write']);
        $token = Fixtures::tokenFor($alice);

        $auth = $this->makeAuth();
        $req = $this->reqWithToken($token->toString());

        $principal = $auth->authenticate($req);

        self::assertNotNull($principal);
        self::assertSame('alice', $principal->id());
        self::assertContains('orders.read', $principal->scopes());
    }

    #[Test]
    public function bad_signature_yields_null(): void
    {
        $alice = new SimplePrincipal('alice');
        $token = Fixtures::tokenFor($alice)->toString();

        // Tamper with the signature segment.
        $parts = explode('.', $token);
        $parts[2] = (string) preg_replace('/[A-Za-z]/', 'X', $parts[2]);
        $tampered = implode('.', $parts);

        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken($tampered)));
    }

    #[Test]
    public function expired_token_yields_null(): void
    {
        $expired = Fixtures::tokenFor(
            new SimplePrincipal('alice'),
            new DateInterval('PT0M'),  // expired immediately at issue time
        );

        // Force expiry in the past by waiting a second isn't reliable;
        // build a token whose exp is BEFORE now-leeway by passing a negative interval.
        // lcobucci/jwt's Configuration::validationConstraints will reject it.
        sleep(1);

        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken($expired->toString())));
    }

    #[Test]
    public function malformed_token_yields_null(): void
    {
        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken('not-a-jwt')));
    }

    #[Test]
    public function no_token_yields_null(): void
    {
        $auth = $this->makeAuth();
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($auth->authenticate($req));
    }

    #[Test]
    public function claims_mapper_receives_the_parsed_token_claims(): void
    {
        $captured = [];
        $auth = new JwtAuthenticator(
            Fixtures::hs256Config(),
            new BearerTokenExtractor(),
            static function ($token) use (&$captured): ?Principal {
                $captured = $token->claims()->all();

                return new SimplePrincipal((string) $token->claims()->get('sub'));
            },
        );

        $token = Fixtures::tokenFor(new SimplePrincipal('alice', roles: ['admin']));
        $auth->authenticate($this->reqWithToken($token->toString()));

        self::assertSame('alice', $captured['sub'] ?? null);
        self::assertSame(['admin'], $captured['roles'] ?? null);
    }

    private function makeAuth(): JwtAuthenticator
    {
        return new JwtAuthenticator(
            Fixtures::hs256Config(),
            new BearerTokenExtractor(),
            static fn($token) => new SimplePrincipal(
                (string) $token->claims()->get('sub'),
                claims: $token->claims()->all(),
            ),
        );
    }

    private function reqWithToken(string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }
}
```

- [ ] **Step 3: Run, expect failure**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/JwtAuthenticatorTest.php
```

- [ ] **Step 4: Write JwtAuthenticator**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Closure;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\Clock\SystemClock;
use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Verifies a JWT (HS256/RS256/ES256/EdDSA per the configured signer) and
 * delegates Principal construction to the claims-mapper closure.
 *
 *   $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
 *   $auth = new JwtAuthenticator(
 *       $jwt,
 *       new BearerTokenExtractor(),
 *       fn (UnencryptedToken $t) => new SimplePrincipal(
 *           id: (string) $t->claims()->get('sub'),
 *           scopes: explode(' ', (string) $t->claims()->get('scope', '')),
 *       ),
 *   );
 *
 * Failures (bad signature, expired, malformed) return null — never throw.
 * The reason is logged via PSR-3 at info/debug, never disclosed on the wire.
 */
final class JwtAuthenticator implements Authenticator
{
    private readonly TokenExtractor $extractor;

    private readonly LoggerInterface $logger;

    /** @var Closure(Plain): ?Principal */
    private readonly Closure $claimsMapper;

    /**
     * @param Closure(Plain): ?Principal $claimsMapper
     */
    public function __construct(
        private readonly Configuration $jwt,
        ?TokenExtractor $extractor = null,
        ?Closure $claimsMapper = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->extractor = $extractor ?? new BearerTokenExtractor();
        $this->claimsMapper = $claimsMapper ?? static fn() => null;
        $this->logger = $logger ?? new NullLogger();
    }

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $token = $this->extractor->extract($request);

        if ($token === null) {
            return null;
        }

        try {
            $parsed = $this->jwt->parser()->parse($token);
        } catch (Throwable $e) {
            $this->logger->debug('auth.token.malformed', ['error' => $e::class]);

            return null;
        }

        if (!$parsed instanceof Plain) {
            $this->logger->info('auth.token.unsupportedFormat');

            return null;
        }

        try {
            $this->jwt->validator()->assert(
                $parsed,
                new SignedWith($this->jwt->signer(), $this->jwt->verificationKey()),
                new StrictValidAt(SystemClock::fromUTC()),
            );
        } catch (RequiredConstraintsViolated $e) {
            $this->logger->info('auth.token.constraintsViolated', [
                'errors' => array_map(static fn($v) => $v->getMessage(), $e->violations()),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->logger->info('auth.token.validationFailed', ['error' => $e::class]);

            return null;
        }

        return ($this->claimsMapper)($parsed);
    }
}
```

- [ ] **Step 5: Re-run JWT tests**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Unit/Authenticator/JwtAuthenticatorTest.php
```

Expected: `OK (6 tests, … assertions)`.

If `expired_token_yields_null` fails due to clock leeway in lcobucci/jwt, adjust the test to use a token built with `expiresAt($now->modify('-1 minute'))` — issue a token with an explicit past expiry.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-http-auth/src/Authenticator/JwtAuthenticator.php packages/nexus-http-auth/tests/Unit/Authenticator/JwtAuthenticatorTest.php packages/nexus-http-auth/tests/Support/Fixtures.php
git -c commit.gpgsign=false commit -m "feat(http-auth): JwtAuthenticator on lcobucci/jwt ^5

Verifies signature + expiry + (issuer/audience when configured) via
SignedWith + StrictValidAt constraints. All recoverable failures
silently degrade to null Principal — never throw on bad tokens. Reasons
logged via PSR-3 at debug/info, never disclosed on the wire.

Includes Fixtures helper with stable HS256 keypair for tests."
```

---

## Task 15: HttpTestClient integration test

End-to-end test using the toolkit's `HttpTestClient` to wire up an `HttpApplication` with `AuthenticationMiddleware` + `AuthorizationMiddleware` and assert the 6 integration scenarios from the spec.

**Files:**
- Create: `packages/nexus-http-auth/tests/Integration/HttpAuthIntegrationTest.php`
- Create: `packages/nexus-http-auth/tests/Support/InMemoryAuthenticator.php`

- [ ] **Step 1: Write InMemoryAuthenticator helper**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Support;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;

/**
 * @psalm-api
 *
 * Thin wrapper around StaticTokenAuthenticator with BearerTokenExtractor —
 * the canonical fixture for downstream package tests. Exported via
 * autoload-dev (tests/) so other packages can `use` it.
 */
final class InMemoryAuthenticator extends StaticTokenAuthenticator
{
    /** @param array<string, Principal> $tokenToPrincipal */
    public function __construct(array $tokenToPrincipal)
    {
        parent::__construct($tokenToPrincipal, new BearerTokenExtractor());
    }
}
```

- [ ] **Step 2: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Integration;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Tests\Support\InMemoryAuthenticator;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Toolkit\Test\HttpTestClient;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversNothing]
final class HttpAuthIntegrationTest extends TestCase
{
    #[Test]
    public function public_route_accepts_anonymous_request(): void
    {
        HttpTestClient::for($this->buildApp())
            ->get('/health')
            ->assertOk();
    }

    #[Test]
    public function requires_auth_with_valid_token_yields_200(): void
    {
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_alice')
            ->get('/me')
            ->assertOk()
            ->assertJsonPath('id', 'alice');
    }

    #[Test]
    public function requires_auth_without_token_yields_401(): void
    {
        HttpTestClient::for($this->buildApp())
            ->get('/me')
            ->assertUnauthorized()
            ->assertHeaderExists('WWW-Authenticate');
    }

    #[Test]
    public function requires_scope_with_token_missing_scope_yields_403(): void
    {
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_alice')   // alice has only orders.read
            ->post('/orders', ['sku' => 'X'])
            ->assertStatus(403)
            ->assertJsonPath('missing.0', 'orders.write');
    }

    #[Test]
    public function requires_scope_with_token_carrying_scope_yields_201(): void
    {
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_bob')   // bob has read+write
            ->post('/orders', ['sku' => 'X'])
            ->assertCreated();
    }

    private function buildApp(): \Monadial\Nexus\Http\Ws\CompiledApplication
    {
        $system = ActorSystem::create('http-auth-test', new StepRuntime());

        $auth = new InMemoryAuthenticator([
            'k_alice' => new SimplePrincipal('alice', scopes: ['orders.read']),
            'k_bob'   => new SimplePrincipal('bob', scopes: ['orders.read', 'orders.write']),
        ]);

        return HttpApplication::create($system)
            ->middleware(new AuthenticationMiddleware($auth))
            ->middleware(new AuthorizationMiddleware())
            ->get('/health', static fn() => Response::ok())
            ->get('/me', MeHandler::class)
            ->post('/orders', CreateOrderHandler::class)
            ->compile();
    }
}

#[RequiresAuth]
final class MeHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromPrincipal] Principal $principal,
    ): ResponseInterface {
        return JsonResponse::ok(['id' => $principal->id()]);
    }
}

#[RequiresScope('orders.read', 'orders.write')]
final class CreateOrderHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromPrincipal] Principal $principal,
    ): ResponseInterface {
        return JsonResponse::created(['ownedBy' => $principal->id()]);
    }
}
```

- [ ] **Step 3: Run the integration tests**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/tests/Integration/HttpAuthIntegrationTest.php
```

Expected: `OK (5 tests, …)`.

If any test fails, the most likely culprits:
- `_resolvedHandlerClass` attribute not set — re-verify Task 9 patch landed
- `#[FromPrincipal]` not recognised — re-verify Task 8 patch
- Middleware order — `AuthenticationMiddleware` must register BEFORE `AuthorizationMiddleware`

- [ ] **Step 4: Run the full nexus-http-auth suite to verify everything still passes together**

```bash
docker compose exec php-fiber vendor/bin/phpunit packages/nexus-http-auth/
```

Expected: all green (~35 tests + 5 integration).

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-http-auth/tests/Integration/ packages/nexus-http-auth/tests/Support/InMemoryAuthenticator.php
git -c commit.gpgsign=false commit -m "test(http-auth): end-to-end HttpTestClient integration test

Five scenarios via HttpApplication + AuthenticationMiddleware +
AuthorizationMiddleware + #[FromPrincipal] handler injection. Covers
public route, valid-token success, missing-token 401, missing-scope
403, present-scope success."
```

---

## Task 16: Documentation

Two new pages: `docs/packages/http-auth.md` (reference) and `docs/http/auth.md` (guide).

**Files:**
- Create: `website/docs/packages/http-auth.md`
- Create: `website/docs/http/auth.md`
- Modify: `website/sidebars.js`

- [ ] **Step 1: Write `website/docs/packages/http-auth.md`**

Use the structure of existing `packages/http-toolkit.md` as a template. Cover:

1. **Composer name + namespace**
2. **Quick start** (3 lines: register `AuthenticationMiddleware` + `AuthorizationMiddleware`, put `#[RequiresAuth]` on a handler).
3. **`Principal` interface** — id/roles/scopes/claims/hasRole/hasScope.
4. **`SimplePrincipal`** — readonly value object.
5. **`Authenticator` interface** + the three bundled authenticators:
   - `StaticTokenAuthenticator`
   - `ChainAuthenticator`
   - `JwtAuthenticator` (most coverage — Configuration setup, claims-mapper, failure modes).
6. **`TokenExtractor` interface** + Bearer/Header/Cookie variants.
7. **Middleware**:
   - `AuthenticationMiddleware` (never 401s)
   - `AuthorizationMiddleware` (reads attributes, returns 401/403)
8. **Attributes table** — 7 attributes with one-line description each, plus the standard scenarios.
9. **`Authorizer` interface** for custom policies + `#[Authorize(PolicyClass::class)]`.
10. **WebSocket auth** — short note that it works because the upgrade is an HTTP request; `WebSocketContext::request()->getAttribute('principal')`.
11. **onException remap** — 1-paragraph customise the 401/403 body.
12. **Failure-mode reference** — table mirroring the spec's "silent → anonymous" + "explicit 401/403" sections.

- [ ] **Step 2: Write `website/docs/http/auth.md` (the guide)**

Tutorial-style page that ties together the pieces:
- Why auth lives in a separate package
- "Hello, JWT" — wire up a minimal JWT authenticator end-to-end
- Adding `#[RequiresScope]` to a handler
- Custom Principal implementations
- WebSocket auth (one example)
- The `#[Authorize]` policy pattern

Target: ~200 lines, fewer code blocks than the package reference.

- [ ] **Step 3: Wire both pages into sidebars.js**

In `website/sidebars.js`, in the `packages` category items array, add `'packages/http-auth'` right after `'packages/http-toolkit'`.

In the `http` category items array, add `'http/auth'` right after `'http/handlers'`.

- [ ] **Step 4: Boot the docs site and verify both pages render at 200**

```bash
cd website && npm start > /tmp/docs.log 2>&1 &
sleep 8
curl -s -o /dev/null -w "auth-pkg=%{http_code} auth-guide=%{http_code}\n" \
    http://localhost:3000/docs/packages/http-auth \
    http://localhost:3000/docs/http/auth
```

Expected: `auth-pkg=200 auth-guide=200`. Open the URLs and skim each page in the browser to spot rendering issues.

Stop the dev server when done:

```bash
pkill -f "docusaurus start"
```

- [ ] **Step 5: Commit**

```bash
git add website/docs/packages/http-auth.md website/docs/http/auth.md website/sidebars.js
git -c commit.gpgsign=false commit -m "docs(http-auth): add package reference + HTTP guide page"
```

---

## Task 17: Wrap-up — run full repo gates

- [ ] **Step 1: Run the full unit test suite across all packages**

```bash
docker compose exec php-fiber vendor/bin/phpunit --testsuite=unit
```

Expected: all green. Any regression here means an earlier patch broke something downstream.

- [ ] **Step 2: Run Psalm against the whole project**

```bash
docker compose exec php-fiber vendor/bin/psalm --no-cache
```

Expected: 0 errors. If there are issues in the new files, fix them inline. Common culprits: `MixedAssignment` on `$token->claims()->get(...)` return values — add `@var` annotations.

- [ ] **Step 3: Run PHPCS + PHP-CS-Fixer**

```bash
docker compose exec php-fiber vendor/bin/phpcs packages/nexus-http-auth
docker compose exec php-fiber vendor/bin/php-cs-fixer fix packages/nexus-http-auth --dry-run --diff
```

Expected: both clean. Auto-fix any violations and re-commit.

- [ ] **Step 4: Run Deptrac to verify no illegal cross-package imports**

```bash
docker compose exec php-fiber php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac
```

Expected: 0 violations. If `nexus-http-auth` imports something it shouldn't (e.g. from `nexus-http-server-swoole`), fix the import; check `deptrac.yaml` for the layer config.

- [ ] **Step 5: GrumPHP pre-commit dry-run**

```bash
docker compose exec php-fiber vendor/bin/grumphp git:pre-commit --no-interaction
```

Expected: all hooks pass.

- [ ] **Step 6: Final commit / wrap-up — if any cleanup commits were needed in steps 1-5, make a final wrap-up commit**

```bash
git status   # should be clean
git log --oneline feat/nexus-http..HEAD | head -25   # review the auth-spike commits
```

---

## Verification: spec coverage checklist

After all tasks complete, walk through the spec one more time and confirm each requirement has a corresponding task:

- [x] Pluggable contracts (Principal, Authenticator, Authorizer, TokenExtractor) → T3, T4
- [x] SimplePrincipal default → T6
- [x] Token extractors (Bearer, Header, Cookie) → T7
- [x] 7 attributes (FromPrincipal, RequiresAuth, RequiresScope, RequiresAnyScope, RequiresRole, RequiresAnyRole, Authorize) → T8
- [x] AuthenticationMiddleware never 401s → T13
- [x] AuthorizationMiddleware enforces 401/403 with WWW-Authenticate + `missing[]` → T14
- [x] StaticTokenAuthenticator + ChainAuthenticator → T12
- [x] JwtAuthenticator via lcobucci/jwt + claims-mapper → T15
- [x] No information disclosure (`missing[]` never contains Principal claims) → T14 dedicated test
- [x] AuthException → Unauthenticated / Forbidden + onException remapping → T5
- [x] Compile-time `#[Authorize]` validation → InvalidAuthorizerException, T14
- [x] Hard dep on nexus-http only; soft dep on nexus-http-ws → T1 composer
- [x] WebSocket support via the same middleware path → T11 (WS HandlerInstantiator patch), T16 (integration)
- [x] `#[FromPrincipal]` constructor injection → T8 (nexus-http patch), T11 (nexus-http-ws patch)
- [x] PSR-3 logging of every decision → T13, T14
- [x] Documentation → T17

All spec requirements have an implementing task. Plan is complete.

---

## Open assumptions resolved during planning

| Spec assumption | Verified during plan? | Outcome |
|---|---|---|
| `HandlerResolver` has a public resolver registry | ❌ No registry — `describeParams` is hard-coded | T8 patches it to recognise `#[FromPrincipal]` directly via FQCN string |
| `RouteCompiler` exposes attribute-driven middleware injection | ❌ No hook | T9 takes a different approach — `RouterMiddleware` stamps handler class on request; `AuthorizationMiddleware` reflects at request time |
| `lcobucci/jwt ^5` error-to-null mapping behaves as expected | ✅ Will be verified by T15 tests | T15 includes 6 tests covering valid / malformed / expired / bad-sig / no-token / claims-mapper-receives-token |

The three integration-point unknowns from the spec are now fully resolved by the plan's structure.
