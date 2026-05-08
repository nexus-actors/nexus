<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Message;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Imperative message — a request that something be done. Commands target
 * exactly ONE handler. Failures propagate as exceptions; the bus contract
 * is `void` because async dispatch cannot surface a synchronous failure.
 *
 * Convention (enforced by `nexus-psalm`'s ReadonlyMessageBodyRule):
 * concrete commands are `final readonly class`.
 */
interface Command {}
