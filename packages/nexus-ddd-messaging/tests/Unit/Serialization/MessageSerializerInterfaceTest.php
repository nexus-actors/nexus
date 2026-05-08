<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Serialization;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Serialization\MessageSerializer;
use Monadial\Nexus\Ddd\Messaging\Serialization\SerializedMessage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversNothing]
final class MessageSerializerInterfaceTest extends TestCase
{
    #[Test]
    public function declaresSerializeAndDeserialize(): void
    {
        self::assertTrue(method_exists(MessageSerializer::class, 'serialize'));
        self::assertTrue(method_exists(MessageSerializer::class, 'deserialize'));

        $serialize = new ReflectionMethod(MessageSerializer::class, 'serialize');
        self::assertSame(Envelope::class, $serialize->getParameters()[0]->getType()->getName());
        self::assertSame(SerializedMessage::class, $serialize->getReturnType()->getName());

        $deserialize = new ReflectionMethod(MessageSerializer::class, 'deserialize');
        self::assertSame(SerializedMessage::class, $deserialize->getParameters()[0]->getType()->getName());
        self::assertSame(Envelope::class, $deserialize->getReturnType()->getName());
    }
}
