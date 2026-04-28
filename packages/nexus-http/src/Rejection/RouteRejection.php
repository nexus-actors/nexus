<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

use RuntimeException;

/**
 * Base class for all route-handling rejections.
 *
 * Carries a stable machine-readable code, an HTTP status, and a human-readable
 * message (exposed via {@see RuntimeException::getMessage()}). Subclasses provide
 * specific failure scenarios such as missing routes, disallowed methods, or
 * extractor failures. Not declared `final` so the hierarchy can extend it; not
 * abstract because callers may construct it directly for ad-hoc rejections.
 *
 * The `code` slot is exposed as a virtual property hook because
 * `Exception::$code` already occupies the inherited slot as an untyped int —
 * declaring a typed property of the same name would violate LSP.
 */
// phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal.ClassNeitherAbstractNorFinal -- base of an open hierarchy that callers may also instantiate directly
class RouteRejection extends RuntimeException
{
    /**
     * @var string
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public $code {
        get => $this->errorCode;
    }

    private readonly string $errorCode;

    public function __construct(string $code, string $message, public readonly int $status = 400)
    {
        $this->errorCode = $code;

        parent::__construct($message);
    }
}
