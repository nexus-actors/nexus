<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\ActorRegistration;
use Monadial\Nexus\Http\Exception\DuplicateActorNameException;

/**
 * @psalm-api
 *
 * Mutable boot-time table of actor declarations. Frozen at HttpApp::compile()
 * into a list of ActorRegistrationEntry that feed ResolvedActorTable.
 */
final class ActorRegistry
{
    /** @var array<string, array{props: Props, registration: ActorRegistration}> */
    private array $entries = [];

    /** @return list<ActorRegistrationEntry> */
    public function freeze(): array
    {
        $entries = [];

        foreach ($this->entries as $name => $bundle) {
            $reg = $bundle['registration'];
            $entries[] = new ActorRegistrationEntry(
                $name,
                $bundle['props'],
                $reg->currentMode(),
                $reg->currentSupervision(),
                $reg->currentMailbox(),
            );
        }

        return $entries;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function register(string $name, Props $props, ActorMode $initialMode): ActorRegistration
    {
        if (isset($this->entries[$name])) {
            throw new DuplicateActorNameException($name);
        }

        $registration = new ActorRegistration($name, $initialMode);
        $this->entries[$name] = ['props' => $props, 'registration' => $registration];

        return $registration;
    }
}
