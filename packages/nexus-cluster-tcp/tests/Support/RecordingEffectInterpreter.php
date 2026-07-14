<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEffect;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEffectInterpreter;
use Override;

/**
 * Test double recording every interpreted {@see MembershipEffect} in order.
 */
final class RecordingEffectInterpreter implements MembershipEffectInterpreter
{
    /** @var list<MembershipEffect> */
    private array $effects = [];

    #[Override]
    public function interpret(MembershipEffect $effect): void
    {
        $this->effects[] = $effect;
    }

    /**
     * @return list<MembershipEffect>
     */
    public function effects(): array
    {
        return $this->effects;
    }

    /**
     * @template T of MembershipEffect
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        $matched = [];

        foreach ($this->effects as $effect) {
            if ($effect instanceof $type) {
                $matched[] = $effect;
            }
        }

        return $matched;
    }

    public function clear(): void
    {
        $this->effects = [];
    }
}
