<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Marker for `DomainEvent`s that may be published *outside the bounded
 * context* — integration events, public domain notifications, anything
 * the bus is allowed to forward to other BCs / external consumers.
 *
 * **Most domain events are NOT publishable.** Internal facts about
 * aggregate state changes — the kind that fan out to in-process
 * subscribers but never to other contexts — should implement
 * `DomainEvent` only. Implement `PublishableDomainEvent` only when the
 * event has a *stable, public schema* the team is willing to maintain
 * across version changes for outside consumers.
 *
 * The messaging layer (nexus-ddd-messaging) uses `instanceof
 * PublishableDomainEvent` to decide which events leave the BC. Events
 * that are merely internal `DomainEvent` instances stay local even if
 * the bus subscribes a cross-BC consumer to "all events".
 *
 * Convention: the same `final readonly class` rule applies, plus a
 * stronger discipline on schema stability — adding/renaming/removing
 * fields is a versioning event for outside consumers.
 */
interface PublishableDomainEvent extends DomainEvent {}
