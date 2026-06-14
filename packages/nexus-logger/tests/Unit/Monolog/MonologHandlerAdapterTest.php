<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Monolog;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Monolog\MonologHandlerAdapter;
use Monadial\Nexus\Logger\Record;
use Monolog\Handler\TestHandler;
use Monolog\Level as MonologLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonologHandlerAdapter::class)]
final class MonologHandlerAdapterTest extends TestCase
{
    #[Test]
    public function forwards_record_to_monolog_handler(): void
    {
        $test = new TestHandler();
        $adapter = new MonologHandlerAdapter($test);

        $adapter->handle(new Record(Level::Warning, 'something broke', ['code' => 42], 'http', 1_700_000_000.250));

        self::assertCount(1, $test->getRecords());
        $monoRec = $test->getRecords()[0];
        self::assertSame('something broke', $monoRec->message);
        self::assertSame('http', $monoRec->channel);
        self::assertSame(['code' => 42], $monoRec->context);
        self::assertSame(MonologLevel::Warning, $monoRec->level);
    }

    #[Test]
    public function preserves_timestamp_with_ms_precision(): void
    {
        $test = new TestHandler();
        (new MonologHandlerAdapter($test))->handle(
            new Record(Level::Info, 'tick', [], 'app', 1_700_000_000.123),
        );

        $monoRec = $test->getRecords()[0];
        self::assertSame(1_700_000_000, $monoRec->datetime->getTimestamp());
        self::assertSame(123_000, (int) $monoRec->datetime->format('u'));
    }

    #[Test]
    public function mdc_extra_flows_into_monolog_extra(): void
    {
        $test = new TestHandler();
        (new MonologHandlerAdapter($test))->handle(new Record(
            level: Level::Info,
            message: 'user logged in',
            context: ['userId' => 7],
            channel: 'http',
            timestamp: 1_700_000_000.0,
            extra: ['host' => 'thread-0', 'pid' => 1234, 'requestId' => 'abc'],
        ));

        $r = $test->getRecords()[0];
        self::assertSame(['userId' => 7], $r->context);
        self::assertSame(['host' => 'thread-0', 'pid' => 1234, 'requestId' => 'abc'], $r->extra);
    }

    #[Test]
    public function monolog_processors_run_before_delegate(): void
    {
        $test = new TestHandler();
        $adapter = new MonologHandlerAdapter(
            $test,
            [
                static fn($r) => $r->with(extra: [...$r->extra, 'tag' => 'a']),
                static fn($r) => $r->with(extra: [...$r->extra, 'seq' => 1]),
            ],
        );

        $adapter->handle(new Record(Level::Info, 'm', [], 'app', 1.0));

        $r = $test->getRecords()[0];
        self::assertSame('a', $r->extra['tag'] ?? null);
        self::assertSame(1, $r->extra['seq'] ?? null);
    }

    #[Test]
    public function level_mapping_covers_all_psr3_levels(): void
    {
        $test = new TestHandler();
        $adapter = new MonologHandlerAdapter($test);

        foreach (Level::cases() as $level) {
            $adapter->handle(new Record($level, 'msg', [], 'app', 0.0));
        }

        $records = $test->getRecords();
        self::assertCount(8, $records);
        $monologLevels = array_map(static fn($r) => $r->level, $records);
        self::assertContains(MonologLevel::Debug, $monologLevels);
        self::assertContains(MonologLevel::Info, $monologLevels);
        self::assertContains(MonologLevel::Notice, $monologLevels);
        self::assertContains(MonologLevel::Warning, $monologLevels);
        self::assertContains(MonologLevel::Error, $monologLevels);
        self::assertContains(MonologLevel::Critical, $monologLevels);
        self::assertContains(MonologLevel::Alert, $monologLevels);
        self::assertContains(MonologLevel::Emergency, $monologLevels);
    }
}
