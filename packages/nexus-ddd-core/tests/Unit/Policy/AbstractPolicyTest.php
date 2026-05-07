<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Policy;

use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use Monadial\Nexus\Ddd\Core\Policy\ComposedPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractPolicy::class)]
#[CoversClass(ComposedPolicy::class)]
final class AbstractPolicyTest extends TestCase
{
    #[Test]
    public function policyComputesOutputFromInput(): void
    {
        $policy = new DoublingPolicy();
        self::assertSame(10, $policy->apply(5));
    }

    #[Test]
    public function thenChainsTwoPoliciesIntoAComposedPolicy(): void
    {
        $double = new DoublingPolicy();
        $stringify = new StringifyPolicy();
        $composed = $double->then($stringify);

        self::assertInstanceOf(AbstractPolicy::class, $composed);
        self::assertSame('10', $composed->apply(5));   // 5 → 10 → "10"
    }

    #[Test]
    public function composedPolicyIsItselfAPolicyAndCanBeFurtherComposed(): void
    {
        $double = new DoublingPolicy();
        $stringify = new StringifyPolicy();
        $exclaim = new ExclaimPolicy();
        $composed = $double->then($stringify)->then($exclaim);

        self::assertSame('10!', $composed->apply(5));   // 5 → 10 → "10" → "10!"
    }
}

/** @extends AbstractPolicy<int, int> */
final class DoublingPolicy extends AbstractPolicy
{
    /** @param int $input @return int */
    #[\Override]
    public function apply(mixed $input): mixed
    {
        return $input * 2;
    }
}

/** @extends AbstractPolicy<int, string> */
final class StringifyPolicy extends AbstractPolicy
{
    /** @param int $input @return string */
    #[\Override]
    public function apply(mixed $input): mixed
    {
        return (string) $input;
    }
}

/** @extends AbstractPolicy<string, string> */
final class ExclaimPolicy extends AbstractPolicy
{
    /** @param string $input @return string */
    #[\Override]
    public function apply(mixed $input): mixed
    {
        return $input . '!';
    }
}
