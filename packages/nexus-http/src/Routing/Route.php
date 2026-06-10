<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Closure;

/**
 * @psalm-api
 *
 * Immutable route definition. Produced by the fluent builder and by
 * attribute discovery; consumed by Dispatcher and HandlerResolver.
 *
 * The `handler` is either a class name string, a 'Class::method' string,
 * or a Closure. HandlerResolver compiles each to a ResolvedHandler.
 */
final readonly class Route
{
    /** @param list<string> $middleware Fully-qualified middleware class names. */
    public function __construct(
        public string $method,
        public string $path,
        public string|Closure $handler,
        public array $middleware,
        public ?string $name,
    ) {}

    /** @param list<string> $middleware */
    public function withAddedMiddleware(array $middleware): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            [...$this->middleware, ...$middleware],
            $this->name,
        );
    }

    public function withPrefixedPath(string $prefix): self
    {
        $combined = rtrim($prefix, '/') . '/' . ltrim($this->path, '/');
        $combined = $combined === ''
            ? '/'
            : $combined;

        return new self($this->method, $combined, $this->handler, $this->middleware, $this->name);
    }
}
