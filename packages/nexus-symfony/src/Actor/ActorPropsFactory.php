<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Psr\Container\ContainerInterface;

final class ActorPropsFactory
{
    public function __construct(private readonly ContainerInterface $container, private readonly string $actorClass) {}

    public function create(): Props
    {
        return Props::fromContainer($this->container, $this->actorClass);
    }
}
