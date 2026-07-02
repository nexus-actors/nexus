---
title: Adding a Param Resolver
related:
  - http/handlers
  - reference/attributes
  - contributing/your-first-pr
---

# Adding a Param Resolver

HTTP handler parameters annotated with `#[From*]` attributes are resolved by `ParamResolver` implementations registered on the application. This page explains how to add a new resolver — for example, to pull a value from the session, a custom header, or a service container.

## Checklist

### 1. Create the attribute class

The attribute marks the handler parameter that your resolver will populate:

```php title="src/Http/Attribute/FromSession.php"
<?php

declare(strict_types=1);

namespace App\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromSession
{
    public function __construct(public readonly string $key) {}
}
```

Make the attribute `readonly` and target `Attribute::TARGET_PARAMETER`. The `key` (or equivalent configuration) is read by your resolver at dispatch time.

### 2. Implement `ParamResolver`

`ParamResolver` lives in `nexus-http` (check `packages/nexus-http/src/Resolver/ParamResolver.php` for the exact interface). Implement `resolve()` to return the parameter value from the request:

```php title="src/Http/Resolver/SessionParamResolver.php"
<?php

declare(strict_types=1);

namespace App\Http\Resolver;

use App\Http\Attribute\FromSession;
use App\Session\SessionStore;
use Monadial\Nexus\Http\Resolver\ParamResolver;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionParameter;

final readonly class SessionParamResolver implements ParamResolver
{
    public function __construct(private SessionStore $store) {}

    public function supports(ReflectionParameter $param): bool
    {
        return $param->getAttributes(FromSession::class) !== [];
    }

    public function resolve(ReflectionParameter $param, ServerRequestInterface $request): mixed
    {
        $attr = $param->getAttributes(FromSession::class)[0]->newInstance();

        return $this->store->get($attr->key);
    }
}
```

Return `null` (or throw) if the value is absent and the parameter is non-nullable.

### 3. Register via `HttpApp::registerParamResolver()`

Register the resolver at application boot time, before calling `compile()`:

```php title="src/Http/Bootstrap.php"
$app = HttpApplication::create($system)
    ->registerParamResolver(FromSession::class, new SessionParamResolver($sessionStore))
    ->get('/profile', ProfileHandler::class);
```

Resolvers are tried in registration order. The first resolver whose `supports()` returns `true` for a given parameter wins.

### 4. Update `reference/attributes.md`

Add your attribute to the attributes reference page at `website/docs/reference/attributes.md`. Include:

- The attribute class name (backtick-formatted)
- Target: parameter
- The resolver class it pairs with
- A one-sentence description
- A minimal usage example

## Caveats

- The resolver must be stateless or safe for concurrent use — in a worker pool each worker registers its own resolver instance, but within a single worker the same resolver handles all requests.
- Do not perform blocking I/O (database, network) inside `supports()`. Reserve any I/O for `resolve()`.
- If your resolver's value is not available (missing session key, header not present), either return `null` for nullable parameters or throw a typed exception that your error handler can catch and translate to a 400 or 401 response.

## See also

- [HTTP handlers](../http/handlers.md) — how parameter resolution fits into the dispatch pipeline
- [Attributes reference](../reference/attributes.md) — catalog of all built-in `#[From*]` attributes
