<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Marker;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Typed marker returned by tryDispatch / tryPublish on success. Tracing
 * and correlation ride on MessageContext, not on Accepted — this is
 * intentionally a no-fields type so consumers can match on Either::right
 * without inspecting the payload.
 */
final readonly class Accepted {}
