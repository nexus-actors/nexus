<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Message;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template TResult
 *
 * Interrogative message — a request for information. Queries return a
 * typed result; `Query<TResult>` declares the result type at the call
 * site so the QueryBus's return inference works.
 *
 * Convention (enforced by `nexus-psalm`'s ReadonlyMessageBodyRule):
 * concrete queries are `final readonly class`.
 */
interface Query {}
