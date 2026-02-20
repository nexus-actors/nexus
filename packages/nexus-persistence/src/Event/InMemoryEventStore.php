<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Event;

use Monadial\Nexus\Persistence\PersistenceId;

final class InMemoryEventStore implements EventStore
{
    /** @var array<string, list<EventEnvelope>> */
    private array $events = [];

    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        $key = $id->toString();

        if (!isset($this->events[$key])) {
            $this->events[$key] = [];
        }

        foreach ($events as $event) {
            $this->events[$key][] = $event;
        }
    }

    /** @return iterable<EventEnvelope> */
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        $key = $id->toString();

        if (!isset($this->events[$key])) {
            return [];
        }

        $result = [];

        foreach ($this->events[$key] as $envelope) {
            if ($envelope->sequenceNr >= $fromSequenceNr && $envelope->sequenceNr <= $toSequenceNr) {
                $result[] = $envelope;
            }
        }

        return $result;
    }

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        $key = $id->toString();

        if (!isset($this->events[$key])) {
            return;
        }

        $this->events[$key] = array_values(
            array_filter(
                $this->events[$key],
                static fn (EventEnvelope $e): bool => $e->sequenceNr > $toSequenceNr,
            ),
        );
    }

    public function highestSequenceNr(PersistenceId $id): int
    {
        $key = $id->toString();

        if (!isset($this->events[$key]) || $this->events[$key] === []) {
            return 0;
        }

        $max = 0;

        foreach ($this->events[$key] as $envelope) {
            if ($envelope->sequenceNr > $max) {
                $max = $envelope->sequenceNr;
            }
        }

        return $max;
    }
}
