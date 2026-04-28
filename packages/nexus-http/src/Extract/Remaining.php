<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Override;

/**
 * Greedy extractor: consumes the rest of the path and returns it as a slash-joined string.
 *
 * @implements Extractor<string>
 */
final readonly class Remaining implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): string
    {
        return $segment;
    }
}
