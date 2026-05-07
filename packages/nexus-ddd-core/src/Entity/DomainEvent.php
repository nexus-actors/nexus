<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Marker interface for everything an aggregate emits via `recordThat()`.
 *
 * Convention (enforced by the nexus-psalm plugin's `ReadonlyMessageRule`):
 * concrete domain events MUST be `final readonly class`. They carry the
 * past-tense fact that something happened in the domain — never a request
 * for action (use a Command for that, in `nexus-ddd-messaging`).
 *
 * Identity & metadata (event id, occurred-at, causation/correlation ids,
 * actor) live on the messaging Envelope in nexus-ddd-messaging — not here.
 * A DomainEvent on its own is just the *what*; the messaging layer wraps it
 * with the *when/who/why* when it leaves the aggregate.
 */
interface DomainEvent {}
