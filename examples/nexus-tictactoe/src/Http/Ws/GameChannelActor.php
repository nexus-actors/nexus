<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameEnvelope;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameRejected;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function is_string;

/**
 * One channel actor per game id.
 *
 * Holds three responsibilities:
 *   1. Decode inbound WS frames into pure domain commands.
 *   2. Bind an authenticated identity to the WS connection — the client's
 *      `playerId` is trusted ONLY on the join frame; every subsequent
 *      command is stamped from the per-connection map. A move frame
 *      spoofing another player's id is ignored.
 *   3. Cache the last authoritative snapshot; broadcast on every mutation;
 *      stop the actor when the last connection closes so `ChannelActorRegistry`
 *      can prune (see {@see \Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry::resolveOrSpawn}
 *      for the isAlive() check).
 *
 * State is `?GameSnapshot` — the last authoritative view of the game.
 *
 * @extends WebSocketChannelActor<?GameSnapshot>
 */
final class GameChannelActor extends WebSocketChannelActor
{
    /** @var array<int, string> fd → authenticated playerId (set on join, cleared on close) */
    private array $identities = [];

    public function __construct(
        private readonly EntityRefFactory $games,
        private readonly ClientFrameCodec $codec,
        private readonly LoggerInterface $log,
    ) {}

    #[Override]
    public function initialState(): ?GameSnapshot
    {
        return null;
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ?GameSnapshot $state
     * @return BehaviorWithState<object, ?GameSnapshot>
     */
    #[Override]
    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        $gameId = self::gameIdFrom($conn);
        $this->log->info('ws connection opened', [
            'fd' => $conn->id(),
            'gameId' => $gameId,
            'attached' => count($this->connections()),
            'hasCache' => $state !== null,
        ]);

        if ($state !== null) {
            $conn->send($this->codec->encode($state));
        }

        // Always refresh from the writer — the cache may be stale after
        // a restart. `handleAppMessage` will dedupe / broadcast.
        $this->games->of($gameId)->tell(
            new GameEnvelope(new GetSnapshot(), $ctx->self()),
        );

        return BehaviorWithState::same();
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ?GameSnapshot $state
     * @return BehaviorWithState<object, ?GameSnapshot>
     */
    #[Override]
    public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState {
        $command = $this->codec->decode($frame->text);
        $this->log->debug('ws frame received', [
            'fd' => $conn->id(),
            'command' => $command === null
                ? 'INVALID'
                : $command::class,
        ]);

        if ($command === null) {
            $this->log->warning('ws frame rejected — invalid', [
                'fd' => $conn->id(),
                'text' => substr($frame->text, 0, 120),
            ]);
            $conn->send($this->codec->encodeError('invalid message'));

            return BehaviorWithState::same();
        }

        // Snapshot polls are read-only and reply to the requester alone —
        // otherwise a single client polling would broadcast state to every
        // attached socket. If the cache is warm, skip the actor round-trip
        // entirely.
        if ($command instanceof GetSnapshot) {
            if ($state !== null) {
                $conn->send($this->codec->encode($state));

                return BehaviorWithState::same();
            }

            $this->games->of(self::gameIdFrom($conn))->tell(new GameEnvelope($command, $ctx->self()));

            return BehaviorWithState::same();
        }

        $command = $this->authorize($conn, $command);

        if ($command === null) {
            $this->log->warning('ws frame rejected — player id mismatch', ['fd' => $conn->id()]);
            $conn->send($this->codec->encodeError('player id mismatch'));

            return BehaviorWithState::same();
        }

        $gameId = self::gameIdFrom($conn);
        $this->log->info('command forwarded to game actor', [
            'fd' => $conn->id(),
            'gameId' => $gameId,
            'command' => $command::class,
        ]);
        $this->games->of($gameId)->tell(new GameEnvelope($command, $ctx->self()));

        return BehaviorWithState::same();
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ?GameSnapshot $state
     * @return BehaviorWithState<object, ?GameSnapshot>
     */
    #[Override]
    public function handleAppMessage(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        if ($message instanceof GameRejected) {
            $this->log->info('game rejected — broadcasting error to clients', [
                'reason' => $message->reason,
                'attached' => count($this->connections()),
            ]);
            // The last-mutating client hears the error; we don't broadcast
            // it to spectators (they only see committed state).
            $this->broadcastError($message->reason);

            return BehaviorWithState::same();
        }

        if (!$message instanceof GameSnapshot) {
            return BehaviorWithState::same();
        }

        $this->log->info('snapshot received — broadcasting to clients', [
            'gameId' => $message->gameId,
            'status' => $message->status->value,
            'nextTurn' => $message->nextTurn?->value,
            'attached' => count($this->connections()),
        ]);
        $this->broadcast($this->codec->encode($message));

        /** @var BehaviorWithState<object, ?GameSnapshot> $next */
        $next = BehaviorWithState::next($message);

        return $next;
    }

    /**
     * @param ActorContext<object> $ctx
     * @param ?GameSnapshot $state
     * @return BehaviorWithState<object, ?GameSnapshot>
     */
    #[Override]
    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        unset($this->identities[$conn->id()]);
        $remaining = count($this->connections());

        $this->log->info('ws connection closed', [
            'fd' => $conn->id(),
            'code' => $code,
            'remaining' => $remaining,
        ]);

        if ($this->connections() === []) {
            $this->log->info('channel actor idle — self-passivating');
            /** @var BehaviorWithState<object, ?GameSnapshot> $stop */
            $stop = BehaviorWithState::stopped();

            return $stop;
        }

        return BehaviorWithState::same();
    }

    /**
     * Bind the client-declared `playerId` on join; enforce it thereafter.
     * A move/forfeit whose `playerId` doesn't match the connection's bound
     * identity is rejected — no impersonation across a WS session.
     */
    private function authorize(WebSocketContext $conn, GameCommand $command): ?GameCommand
    {
        $fd = $conn->id();

        if ($command instanceof JoinGame) {
            $this->identities[$fd] = $command->playerId;

            return $command;
        }

        $bound = $this->identities[$fd] ?? null;

        if ($command instanceof GetSnapshot) {
            return $command;
        }

        if ($command instanceof MakeMove && $command->playerId === $bound) {
            return $command;
        }

        if ($command instanceof Forfeit && $command->playerId === $bound) {
            return $command;
        }

        return null;
    }

    private function broadcastError(string $message): void
    {
        $payload = $this->codec->encodeError($message);

        foreach ($this->connections() as $conn) {
            $conn->send($payload);
        }
    }

    private static function gameIdFrom(WebSocketContext $conn): string
    {
        $id = $conn->request()->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new RuntimeException('game id path-param missing on upgrade request');
        }

        return $id;
    }
}
