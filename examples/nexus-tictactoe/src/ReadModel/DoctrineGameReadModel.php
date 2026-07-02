<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\ReadModel;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;

/**
 * Projects game snapshots into the `games` lobby table.
 *
 * Borrows an EntityManager from the shared pool for the duration of a single
 * upsert (via {@see EntityManagerPool::withEntityManager()}) rather than
 * holding one — the projection is a short write, not a long-lived per-game
 * connection, so it must not pin a pool slot. Coroutine-safe: the pool hands
 * each borrow its own EM.
 */
final readonly class DoctrineGameReadModel implements GameReadModel
{
    public function __construct(private EntityManagerPool $pool) {}

    public function apply(GameSnapshot $snapshot): void
    {
        $this->pool->withEntityManager(static function (EntityManagerInterface $em) use ($snapshot): void {
            $row = $em->find(GameSession::class, $snapshot->gameId) ?? new GameSession($snapshot->gameId);
            $row->applySnapshot($snapshot);
            $em->persist($row);
            $em->flush();
        });
    }
}
