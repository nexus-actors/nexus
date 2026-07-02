<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameEnvelope;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameRejected;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\Seated;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\ClientIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\ForfeitIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\JoinIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\MoveIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\SnapshotIntent;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

use function count;
use function is_string;
use function strlen;

/**
 * One channel actor per game id. Owns the connection→identity binding and
 * fans game state out to every attached socket.
 *
 * Identity is server-owned. A connection proves nothing on `move`/`forfeit`;
 * those frames carry no player id. On `join` the server mints (or, for
 * reconnect, accepts) an unguessable capability `token`, seats the player
 * under it, binds fd→token, and privately hands that one connection its
 * token + mark via a {@see WelcomePayload}. A token is never broadcast, so a
 * client cannot learn — let alone assert — another player's identity.
 *
 * State is `?GameSnapshot`: the last authoritative view, kept current by
 * every mutation reply. When alive it is authoritative (no restart in
 * between); a `null` cache means a freshly (re)spawned actor that must
 * re-read the aggregate.
 *
 * @extends WebSocketChannelActor<?GameSnapshot>
 */
final class GameChannelActor extends WebSocketChannelActor
{
    private const int MAX_FRAME_BYTES = 4096;

    /** @var array<int, string> fd → seat token (bound on join reply, cleared on close). */
    private array $seats = [];

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
        $id = $conn->request()->getAttribute('id');

        if (!is_string($id) || !Ulid::isValid($id)) {
            $this->log->warning('rejecting ws upgrade — game id not a ULID', ['fd' => $conn->id()]);
            $conn->close(1008, 'invalid game id');

            return BehaviorWithState::same();
        }

        $this->log->info('ws connection opened', [
            'attached' => count($this->connections()),
            'fd' => $conn->id(),
            'hasCache' => $state !== null,
        ]);

        if ($state !== null) {
            // Live actor: the cache is authoritative.
            $conn->send($this->codec->encodeSnapshot($state));

            return BehaviorWithState::same();
        }

        // Cold (re)spawn: read the aggregate to populate the cache.
        $this->forward($ctx, $conn, new GetSnapshot());

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
        if (strlen($frame->text) > self::MAX_FRAME_BYTES) {
            $conn->send($this->codec->encodeError('frame too large'));

            return BehaviorWithState::same();
        }

        $intent = $this->codec->decode($frame->text);

        if ($intent === null) {
            $conn->send($this->codec->encodeError('invalid message'));

            return BehaviorWithState::same();
        }

        $command = $this->toCommand($conn, $intent, $state);

        if ($command !== null) {
            $this->forward($ctx, $conn, $command);
        }

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
        if ($message instanceof Seated) {
            return $this->onSeated($message);
        }

        if ($message instanceof GameRejected) {
            // Error goes ONLY to the connection that caused it.
            $this->connection($message->fd)?->send($this->codec->encodeError($message->reason));

            return BehaviorWithState::same();
        }

        if (!$message instanceof GameSnapshot) {
            return BehaviorWithState::same();
        }

        $this->log->info('state changed — broadcasting', [
            'attached' => count($this->connections()),
            'gameId' => $message->gameId,
            'nextTurn' => $message->nextTurn?->value,
            'status' => $message->status->value,
        ]);
        $this->broadcast($this->codec->encodeSnapshot($message));

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
        unset($this->seats[$conn->id()]);

        $this->log->info('ws connection closed', [
            'code' => $code,
            'fd' => $conn->id(),
            'remaining' => count($this->connections()),
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
     * Bind the seat and privately welcome the joining connection, then
     * broadcast the new state to everyone.
     *
     * @return BehaviorWithState<object, ?GameSnapshot>
     */
    private function onSeated(Seated $seated): BehaviorWithState
    {
        $this->seats[$seated->fd] = $seated->token;
        $mark = self::markOf($seated->snapshot, $seated->token);

        $this->log->info('player seated', [
            'fd' => $seated->fd,
            'gameId' => $seated->snapshot->gameId,
            'mark' => $mark,
        ]);

        $this->connection($seated->fd)?->send($this->codec->encodeWelcome($mark, $seated->token));
        $this->broadcast($this->codec->encodeSnapshot($seated->snapshot));

        /** @var BehaviorWithState<object, ?GameSnapshot> $next */
        $next = BehaviorWithState::next($seated->snapshot);

        return $next;
    }

    /**
     * Translate a client intent into a domain command, stamping identity
     * from the server-owned binding. Read polls are answered from cache
     * here and return `null` (nothing to forward).
     *
     */
    private function toCommand(WebSocketContext $conn, ClientIntent $intent, ?GameSnapshot $state): ?GameCommand
    {
        $fd = $conn->id();

        if ($intent instanceof JoinIntent) {
            // Reconnect presents the stored token; a first join mints one.
            $token = $intent->token ?? (string) new Ulid();

            return new JoinGame($token, $intent->name);
        }

        if ($intent instanceof SnapshotIntent) {
            if ($state !== null) {
                $conn->send($this->codec->encodeSnapshot($state));

                return null;
            }

            return new GetSnapshot();
        }

        $token = $this->seats[$fd] ?? null;

        if ($token === null) {
            $conn->send($this->codec->encodeError('join before playing'));

            return null;
        }

        if ($intent instanceof MoveIntent) {
            return new MakeMove($token, $intent->cell);
        }

        if ($intent instanceof ForfeitIntent) {
            return new Forfeit($token);
        }

        return null;
    }

    /**
     * @param ActorContext<object> $ctx
     */
    private function forward(ActorContext $ctx, WebSocketContext $conn, GameCommand $command): void
    {
        $this->games->of(self::gameIdFrom($conn))->tell(
            new GameEnvelope($command, $ctx->self(), $conn->id()),
        );
    }

    private static function markOf(GameSnapshot $snapshot, string $token): ?string
    {
        return match ($token) {
            $snapshot->playerX?->id => 'X',
            $snapshot->playerO?->id => 'O',
            default => null,
        };
    }

    private static function gameIdFrom(WebSocketContext $conn): string
    {
        $id = $conn->request()->getAttribute('id');

        if (!is_string($id) || !Ulid::isValid($id)) {
            // onOpened already rejected non-ULID ids; this is belt-and-suspenders
            // for any frame that somehow arrives on an unvalidated connection.
            throw new InvalidGameIdException('game id must be a ULID');
        }

        return $id;
    }
}
