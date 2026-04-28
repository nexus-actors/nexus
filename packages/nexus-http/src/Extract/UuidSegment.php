<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use InvalidArgumentException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;
use Symfony\Component\Uid\Uuid;

/** @implements Extractor<Uuid> */
final readonly class UuidSegment implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): Uuid
    {
        try {
            return Uuid::fromString($segment);
        } catch (InvalidArgumentException) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected UUID');
        }
    }
}
