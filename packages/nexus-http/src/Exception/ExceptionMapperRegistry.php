<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Closure;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function class_implements;
use function class_parents;

/**
 * @psalm-api
 *
 * Registered mappers from exception class to ResponseInterface. Walk order:
 * class → parents → interfaces. First exact-class match wins; otherwise the
 * first ancestor or interface that has a registered mapper wins.
 */
final class ExceptionMapperRegistry
{
    /**
     * Storage uses bare `Closure` because mappers are stored by exception
     * class — each entry only ever gets invoked with an instance of its
     * key class, but PHP/Psalm can't carry a per-key narrowing through
     * an array. `map()` invokes via `$mapper($e, $r)` which is safe by
     * construction (we only call with an `$e` matching the key class).
     *
     * @var array<class-string, Closure>
     */
    private array $mappers = [];

    /**
     * @template TException of Throwable
     * @param class-string<TException> $exceptionClass
     * @param Closure(TException, ServerRequestInterface): ResponseInterface $mapper
     */
    public function register(string $exceptionClass, Closure $mapper): void
    {
        $this->mappers[$exceptionClass] = $mapper;
    }

    public function map(Throwable $e, ServerRequestInterface $r): ResponseInterface
    {
        $mapper = $this->find($e);

        /** @var ResponseInterface */
        return $mapper($e, $r);
    }

    public function has(string $exceptionClass): bool
    {
        return isset($this->mappers[$exceptionClass]);
    }

    private function find(Throwable $e): Closure
    {
        $class = $e::class;

        if (isset($this->mappers[$class])) {
            return $this->mappers[$class];
        }

        $parents = class_parents($class);

        if ($parents !== false) {
            foreach ($parents as $parent) {
                if (isset($this->mappers[$parent])) {
                    return $this->mappers[$parent];
                }
            }
        }

        $interfaces = class_implements($class);

        if ($interfaces !== false) {
            foreach ($interfaces as $interface) {
                if (isset($this->mappers[$interface])) {
                    return $this->mappers[$interface];
                }
            }
        }

        if (isset($this->mappers[Throwable::class])) {
            return $this->mappers[Throwable::class];
        }

        throw new LogicException("No exception mapper for {$class} and no Throwable fallback registered.");
    }
}
