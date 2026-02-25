<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Recovery;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Exception\WriterConflictException;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Recovery\ReplayFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ReplayFilter::class)]
final class ReplayFilterTest extends TestCase
{
    private PersistenceId $persistenceId;
    private Ulid $writerA;
    private Ulid $writerB;

    #[Test]
    public function fail_mode_passes_single_writer_events(): void
    {
        $filter = ReplayFilter::fail();
        $events = $this->eventsFromWriter($this->writerA, 3);

        $result = $filter->filter($this->persistenceId, $events, new NullLogger());

        self::assertCount(3, $result);
    }

    #[Test]
    public function fail_mode_throws_on_interleaved_writers(): void
    {
        $filter = ReplayFilter::fail();
        $events = [
            $this->event(1, $this->writerA),
            $this->event(2, $this->writerA),
            $this->event(3, $this->writerB),
        ];

        $this->expectException(WriterConflictException::class);

        $filter->filter($this->persistenceId, $events, new NullLogger());
    }

    #[Test]
    public function warn_mode_passes_all_events_from_mixed_writers(): void
    {
        $filter = ReplayFilter::warn();
        $events = [
            $this->event(1, $this->writerA),
            $this->event(2, $this->writerB),
            $this->event(3, $this->writerA),
        ];

        $result = $filter->filter($this->persistenceId, $events, new NullLogger());

        self::assertCount(3, $result);
    }

    #[Test]
    public function repair_by_discard_old_keeps_latest_writer_events(): void
    {
        $filter = ReplayFilter::repairByDiscardOld();
        $events = [
            $this->event(1, $this->writerA),
            $this->event(2, $this->writerA),
            $this->event(3, $this->writerB),
            $this->event(4, $this->writerB),
        ];

        $result = $filter->filter($this->persistenceId, $events, new NullLogger());

        self::assertCount(2, $result);
        self::assertSame(3, $result[0]->sequenceNr);
        self::assertSame(4, $result[1]->sequenceNr);
    }

    #[Test]
    public function off_mode_passes_everything(): void
    {
        $filter = ReplayFilter::off();
        $events = [
            $this->event(1, $this->writerA),
            $this->event(2, $this->writerB),
        ];

        $result = $filter->filter($this->persistenceId, $events, new NullLogger());

        self::assertCount(2, $result);
    }

    #[Test]
    public function empty_events_produce_empty_result(): void
    {
        $filter = ReplayFilter::fail();

        $result = $filter->filter($this->persistenceId, [], new NullLogger());

        self::assertSame([], $result);
    }

    #[Test]
    public function single_event_always_passes(): void
    {
        $filter = ReplayFilter::fail();
        $events = [$this->event(1, $this->writerA)];

        $result = $filter->filter($this->persistenceId, $events, new NullLogger());

        self::assertCount(1, $result);
    }

    protected function setUp(): void
    {
        $this->persistenceId = PersistenceId::of('Test', 'test-1');
        $this->writerA = new Ulid();
        $this->writerB = new Ulid();
    }

    private function event(int $seqNr, Ulid $writerId): EventEnvelope
    {
        return new EventEnvelope(
            persistenceId: $this->persistenceId,
            sequenceNr: $seqNr,
            event: new stdClass(),
            eventType: 'stdClass',
            timestamp: new DateTimeImmutable(),
            writerId: $writerId,
        );
    }

    /** @return list<EventEnvelope> */
    private function eventsFromWriter(Ulid $writerId, int $count): array
    {
        $events = [];

        for ($i = 1; $i <= $count; $i++) {
            $events[] = $this->event($i, $writerId);
        }

        return $events;
    }
}
