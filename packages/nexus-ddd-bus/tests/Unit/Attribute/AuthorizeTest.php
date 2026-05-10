<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Authorize::class)]
final class AuthorizeTest extends TestCase
{
    #[Test]
    public function targetsMethods(): void
    {
        $reflection = new ReflectionClass(Authorize::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD, $meta->flags);
    }

    #[Test]
    public function constructsWithPolicyOnly(): void
    {
        $attr = new Authorize(policy: 'order.cancel');

        self::assertSame('order.cancel', $attr->policy);
        self::assertNull($attr->subject);
        self::assertNull($attr->before);
    }

    #[Test]
    public function constructsWithSubjectAndBefore(): void
    {
        $attr = new Authorize(policy: 'order.cancel', subject: 'orderId', before: 'validation');

        self::assertSame('order.cancel', $attr->policy);
        self::assertSame('orderId', $attr->subject);
        self::assertSame('validation', $attr->before);
    }
}
