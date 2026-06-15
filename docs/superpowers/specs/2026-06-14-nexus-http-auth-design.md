# nexus-http-auth Design

**Status:** Spec — pending review
**Author:** brainstorming session 2026-06-14
**Target package:** `nexus-actors/http-auth` (new)
**Namespace:** `Monadial\Nexus\Http\Auth\`

## Goal

Provide authentication and authorization for the Nexus HTTP stack via
pluggable contracts, with a working JWT authenticator out of the box.
HTTP routes and WebSocket upgrades use the same auth path. Routes
declare requirements via constructor attributes; failures map to 401 /
403 with no information disclosure.

## Out of scope

- Session management, login forms, password hashing
- OAuth2 token endpoint, OIDC discovery
- CSRF (separate package, future)
- User repository / persistent identity store
- Refresh-token rotation (the verifier accepts whatever tokens its
  issuer hands out)

## Package boundary

**Hard dependencies:**

- `nexus-actors/http` `self.version`
- `lcobucci/jwt` `^5`
- `psr/http-message` `^2.0`
- `psr/http-server-middleware` `^1.0`
- `psr/http-factory` `^1.1`

**Soft dependencies (`suggest` in composer.json; `require-dev` for tests):**

- `nexus-actors/http-ws` — install when you want WebSocket auth. The
  same middleware works on the WS upgrade because the upgrade IS an
  HTTP request. `http-auth` has no `use` statement on any `http-ws`
  class.
- `nexus-actors/logger` — used by middleware for auth-failure log
  emission with MDC integration; absent install means no logs.

**Dependency graph users see:**

| User scenario | Installs |
|---|---|
| HTTP API + auth | `nexus-http` + `nexus-http-auth` |
| HTTP + WS + auth | `nexus-http` + `nexus-http-ws` + `nexus-http-auth` |

`http-auth` never pulls `http-ws` into a deployment that doesn't need
it. The WebSocket integration is conventional (same middleware, same
attribute, same Principal lookup on `WebSocketContext::request()`),
not enforced by composer.

**Public surface:** ~18 user-facing classes/interfaces. Concrete
authenticators beyond JWT (API keys, OIDC, session cookies) live in
separate adapter packages.

## Architecture

```
ServerRequestInterface
        │
        ▼
AuthenticationMiddleware  (global)
  ├── TokenExtractor.extract() → ?string
  ├── Authenticator.authenticate() → ?Principal
  └── request->withAttribute('principal', Principal | absent)
        │
        ▼
Global / group middleware
        │
        ▼
AuthorizationMiddleware  (per-route; injected when handler has any
                          #[Requires*] / #[Authorize] attribute)
  ├── no Principal + attribute present  → 401 + WWW-Authenticate
  ├── Principal lacks required scope/role → 403 + {"missing":["..."]}
  ├── #[Authorize(P::class)] policy fails → 403 (no missing field)
  └── all checks pass → handler->handle()
        │
        ▼
HandlerResolver
  └── #[FromPrincipal] → reads request attribute, throws on misconfig
        │
        ▼
Handler::__invoke()
```

WebSocket upgrade: the Swoole adapter runs the HTTP pipeline on the
upgrade request before responding `101 Switching Protocols`. Auth
middleware sees the upgrade request as ordinary HTTP; failure returns
401 with no socket switch. `WebSocketContext::request()->getAttribute('principal')`
reads the Principal inside lifecycle hooks.

## Public surface

### Contracts (`src/`)

```
Principal             interface
  id(): string
  roles(): list<string>
  scopes(): list<string>
  claims(): array<string, mixed>
  hasRole(string $role): bool
  hasScope(string $scope): bool

Authenticator         interface
  authenticate(ServerRequestInterface): ?Principal   // null = anonymous

Authorizer            interface
  authorize(Principal, ServerRequestInterface): bool

TokenExtractor        interface
  extract(ServerRequestInterface): ?string

AuthChallenge         readonly value object
  scheme(): string                // "Bearer"
  realm(): ?string                // "api"

AuthException         abstract base
  ├── Unauthenticated
  └── Forbidden(list<string> $missing)
```

### Default Principal

```
SimplePrincipal       final readonly
  constructor(string $id, list<string> $roles = [], list<string> $scopes = [], array $claims = [])
  Implements Principal — concrete target for the JwtAuthenticator
  claims-mapper.
```

### Middleware (`src/Middleware/`)

```
AuthenticationMiddleware       PSR-15
  constructor(Authenticator, ?LoggerInterface = NullLogger)
  Stamps Principal on the request. Never 401s.

AuthorizationMiddleware        PSR-15
  constructor(?AuthChallenge = new AuthChallenge('Bearer', 'api'),
              ?LoggerInterface = NullLogger)
  Reads request attributes (set by HandlerResolver at match time)
  to decide allow/deny. Returns 401 / 403.
```

### Attributes (`src/Attribute/`)

```
#[RequiresAuth]                                       → 401 if no Principal
#[RequiresScope('orders.read', 'orders.write')]       → 403 if missing ALL
#[RequiresAnyScope('orders.read', 'admin')]           → 403 if missing all
#[RequiresRole('admin')]                              → 403 if missing role
#[RequiresAnyRole('admin', 'staff')]                  → 403 if missing all
#[Authorize(MyPolicy::class)]                         → delegates to Authorizer
#[FromPrincipal]                                      → constructor injection
```

`#[Authorize]` requires the referenced class to implement `Authorizer`.
Validation happens at **compile time** — wrong type throws
`InvalidAuthorizerException` from the application builder.

### Authenticators (`src/Authenticator/`)

```
JwtAuthenticator               main authenticator
  constructor(
      Configuration $jwt,                                  // lcobucci/jwt config
      TokenExtractor $extractor,                            // default Bearer
      Closure(UnencryptedToken): ?Principal $claimsMapper,
      ?LoggerInterface $log = null,
  )

StaticTokenAuthenticator       fixture / test authenticator
  constructor(array<string, Principal> $tokenToPrincipal,
              TokenExtractor $extractor = new BearerTokenExtractor())

ChainAuthenticator             composes multiple authenticators
  constructor(list<Authenticator> $authenticators)
  First non-null wins. Exceptions propagate.
```

### Token extractors (`src/Extractor/`)

```
BearerTokenExtractor           Authorization: Bearer <token>  (default)
HeaderTokenExtractor           configurable header name, e.g. X-Api-Key
CookieTokenExtractor           configurable cookie name
```

## Wiring example

```php
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Attribute\{FromPrincipal, RequiresScope};

// boot
$jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));

$auth = new JwtAuthenticator(
    jwt: $jwt,
    extractor: new BearerTokenExtractor(),
    claimsMapper: static function ($token): ?SimplePrincipal {
        return new SimplePrincipal(
            id: (string) $token->claims()->get('sub'),
            roles: (array) $token->claims()->get('roles', []),
            scopes: explode(' ', (string) $token->claims()->get('scope', '')),
            claims: $token->claims()->all(),
        );
    },
);

$app = WsApplication::create($system)
    ->middleware(new AuthenticationMiddleware($auth, $logger))
    ->get('/health', static fn() => Response::ok())               // anonymous OK
    ->get('/orders', OrderListHandler::class);                    // requires auth

// handler
#[RequiresScope('orders.read')]
final class OrderListHandler
{
    public function __construct(
        #[FromPrincipal] private readonly Principal $principal,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        return JsonResponse::ok([
            'requestedBy' => $this->principal->id(),
            'orders'      => /* … */,
        ]);
    }
}
```

## Data flow

```
HTTP request
   │
   ▼  AuthenticationMiddleware
   │  ├── TokenExtractor.extract($req)         → ?string
   │  ├── Authenticator.authenticate($req)     → ?Principal
   │  │     internally tries to verify; failures swallow → null
   │  │     reason logged at debug/info via PSR-3
   │  └── if Principal: $req = $req->withAttribute('principal', $p)
   │
   ▼  Global / group middleware (untouched)
   │
   ▼  RouterMiddleware matches a route
   │
   ▼  AuthorizationMiddleware (only present on routes with attributes)
   │  ├── collect handler's auth attributes from HandlerMetadata
   │  ├── no Principal + any attribute    → 401 + WWW-Authenticate
   │  ├── RequiresScope ALL not satisfied → 403, missing = diff
   │  ├── RequiresAnyScope none satisfied → 403, missing = required
   │  ├── RequiresRole / AnyRole          → same shape
   │  ├── Authorize policy returns false  → 403, missing = []
   │  └── all pass                        → next handler
   │
   ▼  HandlerResolver
   │  └── #[FromPrincipal] → $req->getAttribute('principal') or throw
   │      (throws if attribute missing: misconfigured middleware)
   │
   ▼  Handler::__invoke()
```

## Error handling

### Authentication failures (silent → anonymous)

| Failure | Log level | Log key |
|---|---|---|
| No token in request | none | — (silent baseline) |
| Malformed token | debug | `auth.token.malformed` |
| Bad signature | info | `auth.token.signatureInvalid` |
| Expired (`exp` past) | debug | `auth.token.expired` |
| Wrong `iss` / `aud` | info | `auth.token.claimsRejected` |
| Authenticator throws | bubbles → 500 | (config bug) |

All recoverable failures produce **null Principal** → anonymous request.
The wire never reveals which one happened: attackers see the same 401
whether the token is malformed or expired.

### Authorization failures (explicit responses)

```
401 Unauthorized
  WWW-Authenticate: Bearer realm="api", error="invalid_token"
  Content-Type: application/json
  {"error":"unauthenticated"}

403 Forbidden
  Content-Type: application/json
  {"error":"forbidden","missing":["orders.read"]}
```

`missing` lists constraints that failed, never constraints the Principal
*does* have (information disclosure). For `#[Authorize(P::class)]`
failures, `missing` is empty (policy may fail for opaque reasons).

### Configuration errors (build-time or 500)

| Error | Class | When |
|---|---|---|
| `#[FromPrincipal]` but no middleware registered | `AuthMiddlewareNotRegisteredException` | request-time 500 with diagnostic hint |
| `#[Authorize(X::class)]` where `X` isn't `Authorizer` | `InvalidAuthorizerException` | compile-time (`compile()` throws) |
| JWT key invalid for the chosen signer | bubbles from `lcobucci/jwt` | boot-time, factory throws |

### onException integration

`Unauthenticated` and `Forbidden` are public exception classes. Users
can re-map them for custom response shapes:

```php
$app->onException(Unauthenticated::class, static fn() =>
    JsonResponse::ok(['error' => 'login required'])->withStatus(401));
```

Defaults are sensible; remap is opt-in.

### Logging

Every authentication / authorization decision emits one PSR-3 line:

- `debug` for anonymous flow-through and successful Principal stamp
- `info` for authorization denied (Principal present but lacking)
- `info` for token verification failures (signature, expiry, claims)

Principal id is logged; tokens never are. With MDC populated by
`AuthenticationMiddleware`, every downstream log line in the request
carries `principalId`.

## Testing strategy

### Unit (`tests/Unit/`, target ~30 tests)

| Suite | Coverage |
|---|---|
| `SimplePrincipalTest` | `hasRole`/`hasScope`/claim getters, immutability |
| `StaticTokenAuthenticatorTest` | Lookup, null on missing/unknown token |
| `ChainAuthenticatorTest` | First-non-null wins, exceptions propagate, empty chain → null |
| `JwtAuthenticatorTest` | Valid → Principal; bad sig / expired / wrong-iss / wrong-aud → null with correct log key; malformed → null; tampered → null; claims-mapper receives parsed token |
| `BearerTokenExtractorTest` | Header present/absent/wrong-scheme; whitespace tolerance |
| `HeaderTokenExtractorTest` | Configured header name |
| `CookieTokenExtractorTest` | Cookie present/absent |
| `AuthenticationMiddlewareTest` | Principal stamped; null leaves request unchanged; downstream sees both |
| `AuthorizationMiddlewareTest` | One per attribute combo: `RequiresAuth` w/ + w/o Principal, `RequiresScope` ALL, `RequiresAnyScope` any-of, role variants, `Authorize` callable; correct 401 vs 403; correct `missing` payload; never-disclose-claims invariant |
| `SimplePolicyAuthorizerTest` | Reference Authorizer impl for users to copy |

### Integration (`tests/Integration/`, ~6 tests via `HttpTestClient`)

```
1. Public route (no attributes) + anonymous request          → 200
2. RequiresAuth + valid bearer JWT                           → 200, principal injected
3. RequiresAuth + no token                                   → 401 + WWW-Authenticate
4. RequiresScope('orders.read') + token without scope        → 403, missing: ['orders.read']
5. WS upgrade with valid JWT in Authorization                → 101, $ctx has principal
6. WS upgrade with bad JWT                                   → 401 (no socket switch)
```

### Test fixtures (`tests/Support/`)

- `Fixtures::hs256Signer()` / `Fixtures::rs256Signer()` — keypair
  builders for `JwtAuthenticator` tests
- `Fixtures::tokenFor(Principal, ?DateInterval $expiresIn = null)` —
  issues a signed token for the fixture
- `InMemoryAuthenticator` — `Map<token, Principal>` thinly wrapping
  `StaticTokenAuthenticator`, exposed via `autoload-dev` for downstream
  package tests

### Mutation testing

Existing `make mutation` (Infection) runs against `src/`. Target ≥80%
MSI as the repo standard.

### Property-shaped invariants

Not full PBT, but tests checking these properties:

- For any Principal and any non-empty scopes list, `hasScope($s)`
  returns true iff `$s ∈ scopes()`.
- For any `Chain(a, b, c)`, if `a` returns Principal, `b` and `c`
  are never called.
- For any JWT signed with K and verified with K, claims-mapper sees the
  same claims that were issued. Verification with K' yields null.

## File structure

```
packages/nexus-http-auth/
├── composer.json
├── src/
│   ├── Principal.php
│   ├── Authenticator.php
│   ├── Authorizer.php
│   ├── TokenExtractor.php
│   ├── AuthChallenge.php
│   ├── Exception/
│   │   ├── AuthException.php
│   │   ├── Unauthenticated.php
│   │   ├── Forbidden.php
│   │   ├── AuthMiddlewareNotRegisteredException.php
│   │   └── InvalidAuthorizerException.php
│   ├── Principal/
│   │   └── SimplePrincipal.php
│   ├── Middleware/
│   │   ├── AuthenticationMiddleware.php
│   │   └── AuthorizationMiddleware.php
│   ├── Attribute/
│   │   ├── RequiresAuth.php
│   │   ├── RequiresScope.php
│   │   ├── RequiresAnyScope.php
│   │   ├── RequiresRole.php
│   │   ├── RequiresAnyRole.php
│   │   ├── Authorize.php
│   │   └── FromPrincipal.php
│   ├── Authenticator/
│   │   ├── JwtAuthenticator.php
│   │   ├── StaticTokenAuthenticator.php
│   │   └── ChainAuthenticator.php
│   └── Extractor/
│       ├── BearerTokenExtractor.php
│       ├── HeaderTokenExtractor.php
│       └── CookieTokenExtractor.php
└── tests/
    ├── Support/
    │   ├── Fixtures.php
    │   └── InMemoryAuthenticator.php
    ├── Unit/
    │   ├── Principal/SimplePrincipalTest.php
    │   ├── Authenticator/StaticTokenAuthenticatorTest.php
    │   ├── Authenticator/ChainAuthenticatorTest.php
    │   ├── Authenticator/JwtAuthenticatorTest.php
    │   ├── Extractor/BearerTokenExtractorTest.php
    │   ├── Extractor/HeaderTokenExtractorTest.php
    │   ├── Extractor/CookieTokenExtractorTest.php
    │   ├── Middleware/AuthenticationMiddlewareTest.php
    │   └── Middleware/AuthorizationMiddlewareTest.php
    └── Integration/
        └── HttpAuthIntegrationTest.php
```

## Integration with nexus-http

The package extends three existing extension points without modifying
`nexus-http`:

1. **HandlerMetadata extension.** `nexus-http/HandlerResolver` already
   inspects constructor parameters for `#[FromActor]`, `#[FromService]`,
   `#[FromBody]`. We register a new `ParamResolver` for `#[FromPrincipal]`
   that reads the request attribute set by `AuthenticationMiddleware`.
   No core changes; the resolver registers itself through the existing
   resolver registry contract.

2. **Per-route middleware injection.** When `nexus-http/RouteCompiler`
   sees any of the `#[Requires*]` / `#[Authorize]` attributes on a
   handler class, it injects an `AuthorizationMiddleware` instance for
   that route — same mechanism used by `#[Route(middleware: [...])]`.

3. **WebSocket upgrade reuse.** `nexus-http-ws/WebSocketDispatcher`
   already runs the HTTP pipeline against the upgrade request. No
   special wiring; the middleware behaves identically there.

If any of these extension points don't exist yet in the form described,
the implementation plan will need to add them — but the contract shape
is what we want. The plan should verify these assumptions in the
spike.

## Open assumptions to verify during implementation

1. `nexus-http/HandlerResolver` has a public registry for custom
   `#[From*]` resolvers (or we need to add one). If not, the cleanest
   path is to extend the resolver registry. **If `nexus-http-ws`
   maintains a separate registry from `nexus-http`**, `#[FromPrincipal]`
   on `WebSocketHandler` constructors needs conditional registration
   via `class_exists(WebSocketHandler::class)` — keeping `http-ws` a
   soft dep, not a hard one. The implementation plan should verify
   whether the registries are unified before committing to either
   approach.
2. `nexus-http/RouteCompiler` exposes a hook for injecting per-route
   middleware based on handler attributes. If not, we may need to add
   an attribute-driven middleware injector.
3. `lcobucci/jwt ^5` Configuration / Validator behaviour matches our
   expected error-to-null mapping — verify with the spike's
   `JwtAuthenticatorTest`.

These are the three integration points where the plan might surface
unexpected core changes. Calling them out so the plan addresses them
upfront.
