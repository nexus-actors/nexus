<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Override;

/** @implements Extractor<string> */
final readonly class StringSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): string
    {
        return $segment;
    }
}
