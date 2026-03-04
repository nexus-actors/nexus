<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Tracing;

use DateTimeImmutable;
use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use Monadial\Nexus\Symfony\Tracing\NexusMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NexusMonologProcessor::class)]
final class NexusMonologProcessorTest extends TestCase
{
    #[Test]
    public function addsRequestIdFromCoroutineContext(): void
    {
        $context = new MockCoroutineContext();
        $context->current()['nexus.request_id']     = '01JTAAA';
        $context->current()['nexus.correlation_id'] = '01JTBBB';

        $processor = new NexusMonologProcessor($context, new EnvelopeContext($context));
        $record    = $this->makeRecord();

        $result = ($processor)($record);

        self::assertSame('01JTAAA', $result->extra['request_id']);
        self::assertSame('01JTBBB', $result->extra['correlation_id']);
    }

    #[Test]
    public function returnsUnmodifiedRecordWhenNoContextAvailable(): void
    {
        $context   = new MockCoroutineContext();
        $processor = new NexusMonologProcessor($context, new EnvelopeContext($context));
        $record    = $this->makeRecord();

        $result = ($processor)($record);

        self::assertArrayNotHasKey('request_id', $result->extra);
    }

    private function makeRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: [],
        );
    }
}
