<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Transactional {}
