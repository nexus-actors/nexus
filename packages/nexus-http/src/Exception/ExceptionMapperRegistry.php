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
    /** @var array<class-string, Closure(Throwable, ServerRequestInterface): ResponseInterface> */
    private array $mappers = [];

    /** @param Closure(Throwable, ServerRequestInterface): ResponseInterface $mapper */
    public function register(string $exceptionClass, Closure $mapper): void
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->mappers[$exceptionClass] = $mapper;
    }

    public function map(Throwable $e, ServerRequestInterface $r): ResponseInterface
    {
        $mapper = $this->find($e);

        return $mapper($e, $r);
    }

    public function has(string $exceptionClass): bool
    {
        return isset($this->mappers[$exceptionClass]);
    }

    /** @return Closure(Throwable, ServerRequestInterface): ResponseInterface */
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
