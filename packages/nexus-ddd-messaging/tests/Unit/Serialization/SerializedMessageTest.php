<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Serialization;

use Monadial\Nexus\Ddd\Messaging\Serialization\SerializedMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializedMessage::class)]
final class SerializedMessageTest extends TestCase
{
    #[Test]
    public function exposesBodyFormatMessageClass(): void
    {
        $msg = new SerializedMessage('payload', 'json', 'Acme\\Cmd');

        self::assertSame('payload', $msg->body);
        self::assertSame('json', $msg->format);
        self::assertSame('Acme\\Cmd', $msg->messageClass);
    }
}
