<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Stringable;

use function array_diff_key;
use function is_scalar;
use function microtime;
use function preg_replace_callback;

/**
 * @psalm-api
 *
 * Immutable log record carried from the PSR-3 façade through the actor's
 * mailbox to the registered handlers. Placeholder interpolation (PSR-3
 * §1.2) happens at construction so the actor never re-renders the message.
 *
 * Two metadata buckets, Monolog/SLF4J-style:
 *   - context: per-call arguments the caller passed in
 *   - extra: ambient process/thread/request metadata (typically populated
 *     from MDC by the Logger façade)
 */
final readonly class Record
{
    /**
     * @param array<string, mixed> $context Context after consumed placeholders are removed.
     * @param array<string, mixed> $extra Ambient metadata (MDC).
     */
    public function __construct(
        public Level $level,
        public string $message,
        public array $context,
        public string $channel,
        public float $timestamp,
        public array $extra = [],
    ) {}

    /**
     * Factory that performs PSR-3 placeholder interpolation and strips
     * consumed keys from the context, leaving structural fields for the
     * handlers to render.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra Ambient metadata (MDC) — NOT searched for placeholders.
     */
    public static function create(
        Level $level,
        string|Stringable $message,
        array $context,
        string $channel,
        array $extra = [],
    ): self {
        $template = (string) $message;
        $consumed = [];

        $rendered = $context === []
            ? $template
            : (string) preg_replace_callback(
                '/\{(\w+)\}/',
                static function (array $m) use ($context, &$consumed): string {
                    $key = $m[1];

                    if (!isset($context[$key])) {
                        return $m[0];
                    }

                    /** @var mixed $value */
                    $value = $context[$key];

                    if (is_scalar($value) || $value instanceof Stringable) {
                        $consumed[$key] = true;

                        return (string) $value;
                    }

                    return $m[0];
                },
                $template,
            );

        /** @var array<string, true> $consumed */
        $remaining = $consumed === []
            ? $context
            : array_diff_key($context, $consumed);

        return new self($level, $rendered, $remaining, $channel, microtime(true), $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self(
            $this->level,
            $this->message,
            $this->context,
            $this->channel,
            $this->timestamp,
            [...$this->extra, ...$extra],
        );
    }
}
