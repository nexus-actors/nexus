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

        $registration = new ActorRegistration($name, $this, $initialMode);
        $this->entries[$name] = ['props' => $props, 'registration' => $registration];

        return $registration;
    }

    /**
     * Called by ActorRegistration on every mutation. The registration mutates
     * itself in place; the bundle in $entries holds the same instance, so the
     * registry sees fresh values at freeze() time without any extra bookkeeping
     * here. The parameter stays on the signature so future bookkeeping (e.g.
     * change-tracking, freeze-after-first-compile guards) can land as a non-
     * breaking change.
     *
     * @psalm-suppress UnusedParam Part of the public mutation contract.
     */
    public function update(ActorRegistration $registration): void
    {
        // Intentionally empty — registrations mutate in place and the bundle
        // in $entries already holds the same instance; this hook reserves
        // the contract for future bookkeeping (change tracking, freeze
        // guards) without breaking callers.
    }
}
