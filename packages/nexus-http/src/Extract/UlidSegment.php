<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use InvalidArgumentException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;
use Symfony\Component\Uid\Ulid;

/** @implements Extractor<Ulid> */
final readonly class UlidSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): Ulid
    {
        try {
            return Ulid::fromString($segment);
        } catch (InvalidArgumentException) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected ULID');
        }
    }
}
