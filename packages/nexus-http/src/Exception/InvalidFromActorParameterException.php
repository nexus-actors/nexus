<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown at compile time when a parameter attributed with #[FromActor] declares
 * a type that cannot accept an ActorRef. The handler would otherwise compile
 * and fail on first invocation with a TypeError.
 */
final class InvalidFromActorParameterException extends NexusException
{
    public function __construct(string $owner, string $paramName, string $actorName, string $declaredType)
    {
        parent::__construct(
            "#[FromActor('{$actorName}')] parameter \${$paramName} in {$owner} is typed '{$declaredType}', "
            . 'which cannot accept an ' . ActorRef::class . '. '
            . 'Type the parameter as ActorRef (optionally nullable or in a union).',
        );
    }
}
