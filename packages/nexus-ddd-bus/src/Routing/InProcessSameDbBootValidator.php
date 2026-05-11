<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\InProcess;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @psalm-api
 *
 * Boot-time validator (replaces v1's runtime no-op middleware per panel
 * H1). For every `#[InProcess]`-attributed handler method, asserts that
 * the handler's bound connection matches the source aggregate's bound
 * connection. Adopters supply `$bindings` as a `class-string →
 * connection-name` map at application bootstrap; absent bindings cannot
 * conflict and silently pass.
 *
 * The first parameter of an `#[InProcess]`-annotated method is taken as
 * the source event's class. The aggregate-side binding is looked up by
 * that class; the handler-side binding by the declaring class.
 */
final class InProcessSameDbBootValidator
{
    /** @param array<class-string, string> $bindings */
    public function __construct(private readonly array $bindings) {}

    /**
     * @param iterable<class-string> $handlerClasses
     *
     * @throws InProcessConnectionMismatchException
     */
    public function validate(iterable $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            $reflection = new ReflectionClass($handlerClass);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getAttributes(InProcess::class) === []) {
                    continue;
                }

                $this->checkMethodBinding($method);
            }
        }
    }

    private function checkMethodBinding(ReflectionMethod $method): void
    {
        $params = $method->getParameters();

        if ($params === []) {
            return;
        }

        $type = $params[0]->getType();

        if (!$type instanceof ReflectionNamedType) {
            return;
        }

        /** @var class-string $eventClass */
        $eventClass = $type->getName();
        $aggregateConn = $this->bindings[$eventClass] ?? null;
        $declaringClass = $method->getDeclaringClass()->getName();
        $handlerConn = $this->bindings[$declaringClass] ?? null;

        if ($aggregateConn !== null && $handlerConn !== null && $aggregateConn !== $handlerConn) {
            throw InProcessConnectionMismatchException::for($eventClass, $aggregateConn, $handlerConn);
        }
    }
}
