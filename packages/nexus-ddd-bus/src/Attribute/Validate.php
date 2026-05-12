<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Method-level marker that opts the handler's command into the bus
 * `ValidationMiddleware` slot (pipeline stage 5). The `groups` argument
 * forwards into the application `Validator` so adopters can reuse
 * Symfony Validator-style group routing.
 *
 * Boot fails with `MissingValidatorException` when a handler is
 * `#[Validate]`-annotated and no `Validator` slot is registered with
 * the `BusBuilder`, so the attribute is safe to add incrementally:
 * misconfiguration surfaces at composition-root time, not on first
 * dispatch.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Validate
{
    /** @param list<string> $groups */
    public function __construct(public array $groups = []) {}
}
