<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\StringValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(StringValue::class)]
final class ValueTest extends TestCase
{
    #[Test]
    public function implementsStringable(): void
    {
        $value = new readonly class ('hello') extends StringValue {};

        self::assertInstanceOf(Stringable::class, $value);
    }
}
