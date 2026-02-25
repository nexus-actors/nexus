<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Recovery;

use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Exception\WriterConflictException;
use Monadial\Nexus\Persistence\PersistenceId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Detects interleaved writers during event replay (recovery).
 *
 * @psalm-api
 */
final readonly class ReplayFilter
{
    private function __construct(private ReplayFilterMode $mode) {}

    public static function fail(): self
    {
        return new self(ReplayFilterMode::Fail);
    }

    public static function warn(): self
    {
        return new self(ReplayFilterMode::Warn);
    }

    public static function repairByDiscardOld(): self
    {
        return new self(ReplayFilterMode::RepairByDiscardOld);
    }

    public static function off(): self
    {
        return new self(ReplayFilterMode::Off);
    }

    public function mode(): ReplayFilterMode
    {
        return $this->mode;
    }

    /**
     * @param iterable<EventEnvelope> $events
     * @return list<EventEnvelope>
     */
    public function filter(PersistenceId $persistenceId, iterable $events, LoggerInterface $logger): array
    {
        if ($this->mode === ReplayFilterMode::Off) {
            $result = [];

            foreach ($events as $event) {
                $result[] = $event;
            }

            return $result;
        }

        $allEvents = [];
        /** @var ?Ulid $currentWriter */
        $currentWriter = null;

        foreach ($events as $event) {
            if ($currentWriter === null) {
                $currentWriter = $event->writerId;
            }

            if (!$event->writerId->equals($currentWriter)) {
                match ($this->mode) {
                    ReplayFilterMode::Fail => throw new WriterConflictException(
                        $persistenceId,
                        $currentWriter,
                        $event->writerId,
                        $event->sequenceNr,
                    ),
                    ReplayFilterMode::Warn => $logger->warning(
                        'Writer conflict detected for {persistenceId} at sequence {sequenceNr}: '
                        . 'expected writer {expectedWriter}, got {actualWriter}',
                        [
                            'actualWriter' => $event->writerId,
                            'expectedWriter' => $currentWriter,
                            'persistenceId' => $persistenceId->toString(),
                            'sequenceNr' => $event->sequenceNr,
                        ],
                    ),
                    ReplayFilterMode::RepairByDiscardOld => null,
                    ReplayFilterMode::Off => null,
                };

                $currentWriter = $event->writerId;
            }

            $allEvents[] = $event;
        }

        if ($this->mode === ReplayFilterMode::RepairByDiscardOld) {
            $latestWriter = $currentWriter;

            return array_values(array_filter(
                $allEvents,
                static fn(EventEnvelope $e): bool => $latestWriter !== null && $e->writerId->equals($latestWriter),
            ));
        }

        return $allEvents;
    }
}
