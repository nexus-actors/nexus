<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Override;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class DelegatingActorHandler implements ActorHandler
{
    /** @var array<string, ReflectionMethod> */
    private array $handlers = [];

    public function __construct(private readonly object $delegate)
    {
        $ref = new ReflectionClass($delegate);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(AsActorHandler::class) === []) {
                continue;
            }

            $params = $method->getParameters();

            if ($params === []) {
                continue;
            }

            $type = $params[0]->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $this->handlers[$type->getName()] = $method;
        }
    }

    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        $class = $message::class;

        if (!array_key_exists($class, $this->handlers)) {
            return Behavior::unhandled();
        }

        $this->handlers[$class]->invoke($this->delegate, $message);

        return Behavior::same();
    }
}
