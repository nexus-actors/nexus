<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Policy;

use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractPolicy::class)]
final class AbstractPolicyTest extends TestCase
{
    #[Test]
    public function policyComputesOutputFromInput(): void
    {
        $policy = new DoublingPolicy();
        self::assertSame(10, $policy->apply(5));
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
