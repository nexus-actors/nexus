<?php
declare(strict_types=1);

namespace Monadial\Nexus\App;

use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template T of object
 */
final readonly class ActorDefinition
{
    /**
     * @param Props<T> $props
     */
    public function __construct(public string $name, public Props $props) {}
}
