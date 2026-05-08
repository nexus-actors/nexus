<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Handler;

/**
 * @psalm-api
 *
 * Marker for event listeners. Implementers declare ONE of:
 *
 *   public function __invoke(ConcreteEvent $event): void
 *   public function __invoke(ConcreteEvent $event, MessageContext $ctx): void
 *
 * Validated by `EventListenerSignatureRule`. Multiple listeners per event
 * type are allowed (broadcast semantics).
 */
interface EventListener {}
