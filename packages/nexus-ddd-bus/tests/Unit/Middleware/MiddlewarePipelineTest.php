<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function emptyMiddlewareListInvokesCoreDirectly(): void
    {
        $envelope = $this->envelope();
        $captured = null;

        $core = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'core-result';
        };

        $pipeline = new MiddlewarePipeline([], $core);
        $result = $pipeline->dispatch($envelope);

        self::assertSame('core-result', $result);
        self::assertSame($envelope, $captured);
    }

    #[Test]
    public function singleMiddlewareWrapsTheCore(): void
    {
        $envelope = $this->envelope();
        $coreCalled = false;
        $core = static function (Envelope $env) use (&$coreCalled): string {
            $coreCalled = true;

            return 'core';
        };

        $pipeline = new MiddlewarePipeline([new RecordingMiddleware('outer')], $core);
        $result = $pipeline->dispatch($envelope);

        self::assertSame('core', $result);
        self::assertTrue($coreCalled);
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    #[Test]
    public function multipleMiddlewaresRunOutermostFirst(): void
    {
        $envelope = $this->envelope();
        $core = static fn(Envelope $env): string => 'core';

        $pipeline = new MiddlewarePipeline(
            [
                new RecordingMiddleware('first'),
                new RecordingMiddleware('second'),
                new RecordingMiddleware('third'),
            ],
            $core,
        );
        $result = $pipeline->dispatch($envelope);

        self::assertSame('core', $result);
        self::assertSame(['first', 'second', 'third'], RecordingMiddleware::$log);
    }

    #[Test]
    public function innerMiddlewareCanShortCircuitWithoutInvokingCore(): void
    {
        $envelope = $this->envelope();
        $coreCalled = false;
        $core = static function (Envelope $env) use (&$coreCalled): string {
            $coreCalled = true;

            return 'core';
        };

        $pipeline = new MiddlewarePipeline(
            [
                new RecordingMiddleware('outer'),
                new RecordingMiddleware(
                    label: 'short-circuiter',
                    shortCircuitValue: 'short-circuit-result',
                    shortCircuit: true,
                ),
                new RecordingMiddleware('never-reached'),
            ],
            $core,
        );
        $result = $pipeline->dispatch($envelope);

        self::assertSame('short-circuit-result', $result);
        self::assertFalse($coreCalled);
        self::assertSame(['outer', 'short-circuiter'], RecordingMiddleware::$log);
    }

    #[Test]
    public function exceptionFromMiddlewarePropagates(): void
    {
        $envelope = $this->envelope();
        $core = static fn(Envelope $env): string => 'core';

        $pipeline = new MiddlewarePipeline(
            [
                new RecordingMiddleware('outer'),
                new RecordingMiddleware(
                    label: 'thrower',
                    throwOnEnter: new RuntimeException('boom'),
                ),
            ],
            $core,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $pipeline->dispatch($envelope);
    }

    protected function setUp(): void
    {
        RecordingMiddleware::resetLog();
    }

    /**
     * @return Envelope<stdClass>
     */
    private function envelope(): Envelope
    {
        return new Envelope(
            new stdClass(),
            new MessageMetadata(
                id: MessageId::generate(),
                occurredAt: new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC')),
                causationId: Option::none(),
                correlationId: Option::none(),
                conversationId: Option::none(),
                schemaVersion: 1,
                traceParent: Option::none(),
                traceState: Option::none(),
                expiresAt: Option::none(),
                vectorClock: Option::none(),
            ),
        );
    }
}
