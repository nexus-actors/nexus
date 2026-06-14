<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Processor;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Processor\CallerInfoProcessor;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallerInfoProcessor::class)]
final class CallerInfoProcessorTest extends TestCase
{
    #[Test]
    public function captures_class_and_function_from_caller_frame(): void
    {
        $processor = new CallerInfoProcessor();

        $record = $this->invokeFromMethod($processor);

        self::assertSame(self::class, $record->extra['class'] ?? null);
        self::assertSame('invokeFromMethod', $record->extra['function'] ?? null);
    }

    #[Test]
    public function captures_file_and_line_of_caller(): void
    {
        $processor = new CallerInfoProcessor();

        $record = $this->invokeFromMethod($processor);

        self::assertSame(__FILE__, $record->extra['file'] ?? null);
        self::assertIsInt($record->extra['line'] ?? null);
    }

    #[Test]
    public function only_for_skips_levels_outside_the_allow_list(): void
    {
        $processor = CallerInfoProcessor::onlyFor(Level::Error);

        $infoRecord = $processor->process(new Record(Level::Info, 'm', [], 'app', 1.0));
        $errorRecord = $processor->process(new Record(Level::Error, 'm', [], 'app', 1.0));

        self::assertSame([], $infoRecord->extra);
        self::assertSame(self::class, $errorRecord->extra['class'] ?? null);
    }

    #[Test]
    public function only_for_accepts_multiple_levels(): void
    {
        $processor = CallerInfoProcessor::onlyFor(Level::Debug, Level::Error);

        $debug = $processor->process(new Record(Level::Debug, 'm', [], 'app', 1.0));
        $info = $processor->process(new Record(Level::Info, 'm', [], 'app', 1.0));
        $error = $processor->process(new Record(Level::Error, 'm', [], 'app', 1.0));

        self::assertNotSame([], $debug->extra);
        self::assertSame([], $info->extra);
        self::assertNotSame([], $error->extra);
    }

    #[Test]
    public function preserves_existing_extra(): void
    {
        $processor = new CallerInfoProcessor();
        $record = new Record(Level::Info, 'm', [], 'app', 1.0, ['host' => 'h1']);

        $result = $processor->process($record);

        self::assertSame('h1', $result->extra['host'] ?? null);
        self::assertSame(self::class, $result->extra['class'] ?? null);
    }

    private function invokeFromMethod(CallerInfoProcessor $processor): Record
    {
        $record = new Record(Level::Info, 'msg', [], 'app', 1.0);

        return $processor->process($record);
    }
}
