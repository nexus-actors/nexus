<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationContext;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Override;
use Throwable;

/**
 * Test fixture: an `AuthorizationDecider` that records each `decide()`
 * call as a `[policy, subject, context]` tuple and optionally throws a
 * preconfigured exception (e.g., `AccessDeniedException`). Defaults to
 * allowing every decision.
 */
final class RecordingAuthorizationDecider implements AuthorizationDecider
{
    /** @var list<array{context: AuthorizationContext, policy: string, subject: mixed}> */
    public array $calls = [];

    public function __construct(private readonly ?Throwable $throwOnDecide = null) {}

    public static function allowing(): self
    {
        return new self();
    }

    public static function throwingAccessDenied(AccessDeniedException $exception): self
    {
        return new self($exception);
    }

    #[Override]
    public function decide(string $policy, mixed $subject, AuthorizationContext $context): void
    {
        $this->calls[] = ['context' => $context, 'policy' => $policy, 'subject' => $subject];

        if ($this->throwOnDecide !== null) {
            throw $this->throwOnDecide;
        }
    }
}
