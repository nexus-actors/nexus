<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use RuntimeException;

use function is_file;
use function sprintf;

/**
 * @psalm-api
 *
 * Loads a `CompiledBusBootSnapshot` from disk and rebuilds the equivalent
 * `BusBuildResult` WITHOUT running reflection. The snapshot file must have
 * been produced by `CompiledBusBootWriter::writeTo()`. Custom middleware
 * registrations are NOT serialized — adopters wire them at runtime via
 * `BusBuilder::withMiddleware()` and pass them in here (per H13).
 *
 * Extracted from `BusBuilder` so the boot-time reflection orchestrator
 * stays focused on its single responsibility (panel Fowler F1).
 */
final class CompiledBusBootReader
{
    /**
     * @param list<CustomMiddlewareRegistration> $customMiddlewares adopter-supplied splices to graft onto the loaded result
     *
     * @throws RuntimeException when the file is missing or does not return a `CompiledBusBootSnapshot`
     */
    public function readFrom(
        string $path,
        array $customMiddlewares = [],
        int $causationDepthCap = 32,
        int $retryBudgetMs = 5_000,
    ): BusBuildResult {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Compiled snapshot not found at %s', $path));
        }

        /** @var mixed $snapshot */
        $snapshot = require $path;

        if (!$snapshot instanceof CompiledBusBootSnapshot) {
            throw new RuntimeException(sprintf(
                'Compiled snapshot file %s did not return a CompiledBusBootSnapshot instance',
                $path,
            ));
        }

        return new BusBuildResult(
            new HandlerAttributeIndex($snapshot->entries),
            $snapshot->handlerMap,
            $customMiddlewares,
            $causationDepthCap,
            $retryBudgetMs,
        );
    }
}
