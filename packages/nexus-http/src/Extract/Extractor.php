<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

/**
 * @template T
 */
interface Extractor
{
    /**
     * Extracts a typed value from a single path segment.
     *
     * @return T
     */
    public function fromSegment(string $segment): mixed;
}
