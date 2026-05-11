<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Middleware\PerHandlerPipeline;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(PerHandlerPipeline::class)]
final class PerHandlerPipelineTest extends TestCase
{
    #[Test]
    public function dispatchOnRegisteredClassRunsCorrectPipeline(): void
    {
        RecordingMiddleware::resetLog();
        $alphaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('alpha')],
            static fn(Envelope $_e): null => null,
        );
        $betaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('beta')],
            static fn(Envelope $_e): null => null,
        );
        $fallback = new MiddlewarePipeline(
            [new RecordingMiddleware('fallback')],
            static fn(Envelope $_e): null => null,
        );

        $perHandler = new PerHandlerPipeline(
            [AlphaCommand::class => $alphaPipeline, BetaCommand::class => $betaPipeline],
            $fallback,
        );

        $perHandler->dispatch(new Envelope(new AlphaCommand(), MessageMetadata::root($this->clock())));

        self::assertSame(['alpha'], RecordingMiddleware::$log);
    }

    #[Test]
    public function dispatchOnSecondRegisteredClassRunsSecondPipeline(): void
    {
        RecordingMiddleware::resetLog();
        $alphaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('alpha')],
            static fn(Envelope $_e): null => null,
        );
        $betaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('beta')],
            static fn(Envelope $_e): null => null,
        );
        $fallback = new MiddlewarePipeline([], static fn(Envelope $_e): null => null);

        $perHandler = new PerHandlerPipeline(
            [AlphaCommand::class => $alphaPipeline, BetaCommand::class => $betaPipeline],
            $fallback,
        );

        $perHandler->dispatch(new Envelope(new BetaCommand(), MessageMetadata::root($this->clock())));

        self::assertSame(['beta'], RecordingMiddleware::$log);
    }

    #[Test]
    public function dispatchOnUnregisteredClassRunsFallbackPipeline(): void
    {
        RecordingMiddleware::resetLog();
        $fallback = new MiddlewarePipeline(
            [new RecordingMiddleware('fallback')],
            static fn(Envelope $_e): null => null,
        );

        $perHandler = new PerHandlerPipeline([], $fallback);

        $perHandler->dispatch(new Envelope(new AlphaCommand(), MessageMetadata::root($this->clock())));

        self::assertSame(['fallback'], RecordingMiddleware::$log);
    }

    #[Test]
    public function dispatchReturnsValueFromSelectedPipeline(): void
    {
        $alphaPipeline = new MiddlewarePipeline([], static fn(Envelope $_e): string => 'alpha-result');
        $fallback = new MiddlewarePipeline([], static fn(Envelope $_e): string => 'fallback-result');

        $perHandler = new PerHandlerPipeline([AlphaCommand::class => $alphaPipeline], $fallback);

        self::assertSame('alpha-result', $perHandler->dispatch(
            new Envelope(new AlphaCommand(), MessageMetadata::root($this->clock())),
        ));
        self::assertSame('fallback-result', $perHandler->dispatch(
            new Envelope(new BetaCommand(), MessageMetadata::root($this->clock())),
        ));
    }

    #[Test]
    public function selectionIsIndependentBetweenSuccessiveDispatches(): void
    {
        RecordingMiddleware::resetLog();
        $alphaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('alpha')],
            static fn(Envelope $_e): null => null,
        );
        $betaPipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('beta')],
            static fn(Envelope $_e): null => null,
        );
        $fallback = new MiddlewarePipeline([], static fn(Envelope $_e): null => null);

        $perHandler = new PerHandlerPipeline(
            [AlphaCommand::class => $alphaPipeline, BetaCommand::class => $betaPipeline],
            $fallback,
        );

        $perHandler->dispatch(new Envelope(new AlphaCommand(), MessageMetadata::root($this->clock())));
        $perHandler->dispatch(new Envelope(new BetaCommand(), MessageMetadata::root($this->clock())));
        $perHandler->dispatch(new Envelope(new AlphaCommand(), MessageMetadata::root($this->clock())));

        self::assertSame(['alpha', 'beta', 'alpha'], RecordingMiddleware::$log);
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-10T00:00:00+00:00');
            }
        };
    }
}

final readonly class AlphaCommand implements Command {}

final readonly class BetaCommand implements Command {}
