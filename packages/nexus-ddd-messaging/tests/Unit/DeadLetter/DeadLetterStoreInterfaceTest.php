<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\DeadLetter;

use Monadial\Nexus\Ddd\Messaging\DeadLetter\DeadLetterEntry;
use Monadial\Nexus\Ddd\Messaging\DeadLetter\DeadLetterStore;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversNothing]
final class DeadLetterStoreInterfaceTest extends TestCase
{
    #[Test]
    public function declaresRequiredMethods(): void
    {
        self::assertTrue(method_exists(DeadLetterStore::class, 'record'));
        self::assertTrue(method_exists(DeadLetterStore::class, 'replay'));
        self::assertTrue(method_exists(DeadLetterStore::class, 'pending'));

        $record = new ReflectionMethod(DeadLetterStore::class, 'record');
        self::assertSame(DeadLetterEntry::class, $record->getParameters()[0]->getType()->getName());

        $replay = new ReflectionMethod(DeadLetterStore::class, 'replay');
        self::assertSame(MessageId::class, $replay->getParameters()[0]->getType()->getName());
    }
}
