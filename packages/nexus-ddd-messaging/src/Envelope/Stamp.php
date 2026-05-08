<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Envelope;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Marker for transport/cross-cutting metadata extensions. Stamps cover
 * the long tail (serialization, retry counter, transport id, bus name,
 * dispatch attempt) that doesn't belong in the typed `MessageMetadata`.
 */
interface Stamp {}
