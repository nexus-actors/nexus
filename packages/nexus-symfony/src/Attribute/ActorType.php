<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

enum ActorType
{
    case Isolated;
    case Shared;
}
