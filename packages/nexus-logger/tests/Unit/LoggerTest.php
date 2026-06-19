<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\LogActor;
use Monadial\Nexus\Logger\Logger;
use Monadial\Nexus\Logger\Mdc;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Logger\RecordProcessor;
use Monadial\Nexus\Logger\Tests\Unit\Support\CapturingHandler;
use Monadial\Nexus\Logger\Tests\Unit\Support\ExplodingHandler;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

#[CoversClass(Logger::class)]
#[CoversClass(NexusLogger::class)]
#[CoversClass(LogActor::class)]
final class LoggerTest extends TestCase
{
    #[Test]
    public function mdc_values_land_in_extra_not_context(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')->handler($capturing)->build();

        Mdc::put('host', 'thread-0');
        Mdc::put('pid', 1234);
        $logger->info('user logged in', ['userId' => 7]);
        $runtime->drain();

        self::assertSame(['userId' => 7], $capturing->records[0]->context);
        self::assertSame(['host' => 'thread-0', 'pid' => 1234], $capturing->records[0]->extra);
    }

    #[Test]
    public function context_arg_and_mdc_extra_are_independent(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')->handler($capturing)->build();

        Mdc::put('userId', 'fromMdc');
        $logger->info('hi', ['userId' => 'fromArg']);
        $runtime->drain();

        self::assertSame('fromArg', $capturing->records[0]->context['userId']);
        self::assertSame('fromMdc', $capturing->records[0]->extra['userId']);
    }

    #[Test]
    public function info_log_reaches_handler_after_drain(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')->handler($capturing)->build();

        $logger->info('user logged in', ['userId' => 7]);
        $runtime->drain();

        self::assertCount(1, $capturing->records);
        self::assertSame('user logged in', $capturing->records[0]->message);
        self::assertSame(['userId' => 7], $capturing->records[0]->context);
        self::assertSame('app', $capturing->records[0]->channel);
        self::assertSame(Level::Info, $capturing->records[0]->level);
    }

    #[Test]
    public function below_min_level_records_are_dropped_at_the_facade(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')
            ->minLevel(Level::Warning)
            ->handler($capturing)
            ->build();

        $logger->debug('skip-1');
        $logger->info('skip-2');
        $logger->warning('keep-1');
        $logger->error('keep-2');
        $runtime->drain();

        self::assertCount(2, $capturing->records);
        self::assertSame('keep-1', $capturing->records[0]->message);
        self::assertSame('keep-2', $capturing->records[1]->message);
    }

    #[Test]
    public function placeholder_interpolation_runs_on_caller_side(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')->handler($capturing)->build();

        $logger->info('user {name} did {action}', ['action' => 'logout', 'extra' => 'kept', 'name' => 'tomas']);
        $runtime->drain();

        self::assertSame('user tomas did logout', $capturing->records[0]->message);
        self::assertSame(['extra' => 'kept'], $capturing->records[0]->context);
    }

    #[Test]
    public function with_channel_forks_a_logger_sharing_the_same_sink(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $root = NexusLogger::create($system, 'app')->handler($capturing)->build();
        self::assertInstanceOf(Logger::class, $root);

        $httpLogger = $root->withChannel('http');
        $root->info('from app');
        $httpLogger->info('from http');
        $runtime->drain();

        self::assertCount(2, $capturing->records);
        self::assertSame('app', $capturing->records[0]->channel);
        self::assertSame('http', $capturing->records[1]->channel);
    }

    #[Test]
    public function handler_exception_does_not_block_the_next_handler_or_record(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $exploding = new ExplodingHandler();
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')
            ->handler($exploding)
            ->handler($capturing)
            ->build();

        $logger->info('first');
        $logger->info('second');
        $runtime->drain();

        self::assertSame(2, $exploding->calls);
        self::assertCount(2, $capturing->records);
    }

    #[Test]
    public function build_without_a_handler_throws(): void
    {
        $system = ActorSystem::create('logger-test', new StepRuntime());

        $this->expectException(RuntimeException::class);
        NexusLogger::create($system, 'app')->build();
    }

    #[Test]
    public function psr3_log_method_routes_to_correct_level(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();
        $logger = NexusLogger::create($system, 'app')->handler($capturing)->build();

        $logger->log(LogLevel::ERROR, 'something');
        $runtime->drain();

        self::assertSame(Level::Error, $capturing->records[0]->level);
    }

    #[Test]
    public function processors_run_in_order_and_mutate_record_extra(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $capturing = new CapturingHandler();

        $stampHost = new class implements RecordProcessor {
            public function process(Record $record): Record
            {
                return $record->withExtra(['host' => 'h1']);
            }
        };
        $stampPid = new class implements RecordProcessor {
            public function process(Record $record): Record
            {
                return $record->withExtra(['pid' => 42]);
            }
        };

        $logger = NexusLogger::create($system, 'app')
            ->handler($capturing)
            ->processor($stampHost)
            ->processor($stampPid)
            ->build();

        $logger->info('hello');
        $runtime->drain();

        self::assertSame(['host' => 'h1', 'pid' => 42], $capturing->records[0]->extra);
    }

    #[Test]
    public function builder_can_be_threaded_with_console_handler_smoke(): void
    {
        // Smoke test that the factory wires a ConsoleHandler correctly.
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        $runtime = new StepRuntime();
        $system = ActorSystem::create('logger-test', $runtime);
        $logger = NexusLogger::create($system, 'app')
            ->handler(new ConsoleHandler($stream, new LineFormatter()))
            ->build();

        $logger->info('smoke');
        $runtime->drain();

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);
        self::assertStringContainsString('app.INFO: smoke', $contents);
    }

    protected function tearDown(): void
    {
        Mdc::clearStatic();
    }
}
