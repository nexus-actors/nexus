<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Closure;
use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Throwable;

/**
 * Test fixture: records each `process()` invocation order across instances
 * via a shared call log, optionally short-circuits without calling `$next`,
 * or throws a preconfigured exception.
 *
 * @implements Middleware<object, mixed>
 */
final class RecordingMiddleware implements Middleware
{
    /** @var list<string> shared log of `$label` names in invocation order */
    public static array $log = [];

    public function __construct(
        private readonly string $label,
        private readonly mixed $shortCircuitValue = null,
        private readonly bool $shortCircuit = false,
        private readonly ?Throwable $throwOnEnter = null,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        self::$log[] = $this->label;

        if ($this->throwOnEnter !== null) {
            throw $this->throwOnEnter;
        }

        if ($this->shortCircuit) {
            return $this->shortCircuitValue;
        }

        return $next($envelope);
    }

    public static function resetLog(): void
    {
        self::$log = [];
    }
}
