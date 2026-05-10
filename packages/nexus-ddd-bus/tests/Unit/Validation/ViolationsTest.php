<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Validation;

use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Violations::class)]
final class ViolationsTest extends TestCase
{
    #[Test]
    public function emptyReturnsZeroCount(): void
    {
        $vs = Violations::empty();

        self::assertTrue($vs->isEmpty());
        self::assertSame(0, $vs->count());
        self::assertSame([], $vs->all());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenNonEmpty(): void
    {
        $vs = new Violations([new Violation('not_blank', 'must not be blank', 'name')]);

        self::assertFalse($vs->isEmpty());
        self::assertSame(1, $vs->count());
    }

    #[Test]
    public function allReturnsTheBackingList(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('too_short', 'too short', 'email');
        $vs = new Violations([$a, $b]);

        self::assertSame([$a, $b], $vs->all());
    }

    #[Test]
    public function forPathFiltersByExactPath(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('too_short', 'too short', 'email');
        $c = new Violation('format', 'bad format', 'email');
        $vs = new Violations([$a, $b, $c]);

        $filtered = $vs->forPath('email');

        self::assertSame(2, $filtered->count());
        self::assertSame([$b, $c], $filtered->all());
    }

    #[Test]
    public function forPathOnEmptyReturnsEmpty(): void
    {
        $filtered = Violations::empty()->forPath('name');

        self::assertTrue($filtered->isEmpty());
    }

    #[Test]
    public function mergeConcatenatesBothLists(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('too_short', 'too short', 'email');
        $left = new Violations([$a]);
        $right = new Violations([$b]);

        $merged = $left->merge($right);

        self::assertSame(2, $merged->count());
        self::assertSame([$a, $b], $merged->all());
    }

    #[Test]
    public function mergeWithEmptyLeavesOriginalUnchanged(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $left = new Violations([$a]);

        $merged = $left->merge(Violations::empty());

        self::assertSame(1, $merged->count());
        self::assertSame([$a], $merged->all());
    }

    #[Test]
    public function mergeIsImmutableOnReceiver(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('too_short', 'too short', 'email');
        $left = new Violations([$a]);
        $right = new Violations([$b]);

        (void) $left->merge($right);

        self::assertSame([$a], $left->all());
    }
}
