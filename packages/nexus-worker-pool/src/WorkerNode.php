<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\WorkerPool\Directory\WorkerDirectory;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskAck;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskCancel;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskCancelled;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskReply;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskRequest;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use Monadial\Nexus\WorkerPool\Worker\WorkerAskState;
use Monadial\Nexus\WorkerPool\Worker\WorkerReplyRef;

/**
 * @psalm-api
 *
 * Per-worker coordinator within a local worker pool.
 *
 * Owns the ActorSystem, routes messages via the hash ring, and handles
 * incoming transport envelopes. Each worker thread runs exactly one WorkerNode.
 * No serializer is involved — envelopes are passed directly between threads
 * via Thread\Queue (which handles object copying internally).
 */
final class WorkerNode
{
    private const int ASK_RETRY_MAX_ATTEMPTS = 3;
    private const int ASK_RETRY_INTERVAL_MILLIS = 50;
    private const int INBOUND_TERMINAL_TTL_SECONDS = 60;
    private const int INBOUND_TERMINAL_MAX_ENTRIES = 10_000;

    /** @var array<string, LocalActorRef<object>> */
    private array $localRefs = [];

    /** @var array<string, array{
     *     acked: bool,
     *     request: WorkerAskRequest,
     *     retriesRemaining: int,
     *     retryTimer: Cancellable|null,
     *     slot: FutureSlot<object>,
     *     timeout: Cancellable,
     *     target: ActorPath,
     *     targetWorker: int
     * }> */
    private array $pendingOutgoingAsks = [];

    /** @var array<string, WorkerAskState> */
    private array $incomingAskState = [];

    /** @var array<string, WorkerAskRequest> */
    private array $incomingAskRequests = [];

    /** @var array<string, object> */
    private array $incomingAskReplies = [];

    /** @var array<string, int> */
    private array $incomingAskTerminalAt = [];

    public function __construct(
        private readonly int $workerId,
        private readonly ActorSystem $system,
        private readonly WorkerTransport $transport,
        private readonly ConsistentHashRing $ring,
        private readonly WorkerDirectory $directory,
    ) {}

    /**
     * Spawn an actor, routing to local or remote based on the hash ring.
     *
     * If the hash ring assigns the actor name to this worker, the actor is
     * spawned locally via the ActorSystem. Otherwise, a WorkerActorRef is
     * returned that routes messages via the transport.
     *
     * @template T of object
     * @param Props<T> $props
     * @return ActorRef<T>
     */
    public function spawn(Props $props, string $name): ActorRef
    {
        $ownerWorker = $this->ring->getWorker($name);
        $path = ActorPath::fromString('/user/' . $name);
        $pathStr = (string) $path;

        if ($ownerWorker === $this->workerId) {
            $ref = $this->system->spawn($props, $name);
            $this->directory->register($pathStr, $this->workerId);

            if ($ref instanceof LocalActorRef) {
                $this->localRefs[$pathStr] = $ref;
            }

            return $ref;
        }

        $this->directory->register($pathStr, $ownerWorker);

        /** @var WorkerActorRef<T> $workerRef */
        $workerRef = new WorkerActorRef(
            $path,
            $ownerWorker,
            $this->transport,
            $this->directory,
            $this->askRemote(...),
        );

        return $workerRef;
    }

    /**
     * Look up an actor by path, returning a local or remote ref.
     *
     * Returns null if the actor is not known to the directory.
     *
     * @return ActorRef<object>|null
     */
    public function actorFor(string $path): ?ActorRef
    {
        if (isset($this->localRefs[$path])) {
            return $this->localRefs[$path];
        }

        $workerId = $this->directory->lookup($path);

        if ($workerId === null) {
            return null;
        }

        return new WorkerActorRef(
            ActorPath::fromString($path),
            $workerId,
            $this->transport,
            $this->directory,
            $this->askRemote(...),
        );
    }

    /**
     * Start listening for incoming transport envelopes.
     *
     * Incoming envelopes are delivered directly (no deserialization) to the
     * target local actor's mailbox via enqueueEnvelope().
     */
    public function start(): void
    {
        $this->transport->listen(function (Envelope $envelope): void {
            if ($this->handleControlMessage($envelope)) {
                return;
            }

            $targetPath = (string) $envelope->target;
            $ref = $this->localRefs[$targetPath] ?? null;

            if ($ref !== null) {
                $ref->enqueueEnvelope($envelope);
            }
        });
    }

    /**
     * Returns this node's worker ID.
     */
    public function workerId(): int
    {
        return $this->workerId;
    }

    /**
     * Returns the underlying ActorSystem.
     */
    public function system(): ActorSystem
    {
        return $this->system;
    }

    /**
     * @template R of object
     * @return Future<R>
     */
    public function askRemote(ActorPath $targetPath, int $targetWorker, object $message, Duration $timeout): Future
    {
        $requestEnvelope = Envelope::of($message, ActorPath::root(), $targetPath);
        $requestId = $requestEnvelope->requestId;
        $replyPath = ActorPath::fromString("/worker/{$this->workerId}/temp/ask-{$requestId}");
        $request = new WorkerAskRequest(
            requestId: $requestId,
            correlationId: $requestEnvelope->correlationId,
            causationId: $requestEnvelope->causationId,
            targetPath: $targetPath,
            replyToWorker: $this->workerId,
            replyToPath: $replyPath,
            payload: $message,
        );

        $slot = $this->system->runtime()->createFutureSlot();
        $timeoutHandle = $this->system->runtime()->scheduleOnce(
            $timeout,
            function () use ($requestId, $slot, $targetPath, $timeout): void {
                if ($slot->isResolved()) {
                    return;
                }

                $slot->fail(new AskTimeoutException($targetPath, $timeout));
                $this->sendOutgoingAskCancel($requestId);
                $this->clearPendingOutgoingAsk($requestId);
            },
        );

        $this->pendingOutgoingAsks[$requestId] = [
            'acked' => false,
            'request' => $request,
            'retriesRemaining' => self::ASK_RETRY_MAX_ATTEMPTS,
            'retryTimer' => null,
            'slot' => $slot,
            'target' => $targetPath,
            'targetWorker' => $targetWorker,
            'timeout' => $timeoutHandle,
        ];

        $slot->onCancel(function () use ($requestId): void {
            $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

            if ($entry === null) {
                return;
            }

            $this->sendOutgoingAskCancel($requestId);
            $this->clearPendingOutgoingAsk($requestId);
        });

        $this->sendAskRequest($requestId);
        $this->scheduleAskRetry($requestId);

        /** @var Future<R> */
        return new Future($slot);
    }

    private function handleControlMessage(Envelope $envelope): bool
    {
        $message = $envelope->message;

        return match (true) {
            $message instanceof WorkerAskRequest => $this->dispatchWorkerAskRequest($message),
            $message instanceof WorkerAskReply => $this->dispatchWorkerAskReply($message),
            $message instanceof WorkerAskCancel => $this->dispatchWorkerAskCancel($message),
            $message instanceof WorkerAskCancelled => $this->dispatchWorkerAskCancelled($message),
            $message instanceof WorkerAskAck => $this->dispatchWorkerAskAck($message),
            default => false,
        };
    }

    private function dispatchWorkerAskRequest(WorkerAskRequest $message): bool
    {
        $this->handleWorkerAskRequest($message);

        return true;
    }

    private function dispatchWorkerAskReply(WorkerAskReply $message): bool
    {
        $this->handleWorkerAskReply($message);

        return true;
    }

    private function dispatchWorkerAskCancel(WorkerAskCancel $message): bool
    {
        $this->handleWorkerAskCancel($message);

        return true;
    }

    private function dispatchWorkerAskCancelled(WorkerAskCancelled $message): bool
    {
        $this->handleWorkerAskCancelled($message);

        return true;
    }

    private function dispatchWorkerAskAck(WorkerAskAck $message): bool
    {
        $this->handleWorkerAskAck($message);

        return true;
    }

    private function handleWorkerAskRequest(WorkerAskRequest $request): void
    {
        $this->pruneInboundTerminalState();
        $state = $this->incomingAskState[$request->requestId] ?? null;

        if ($state === WorkerAskState::Replied) {
            $cachedReply = $this->incomingAskReplies[$request->requestId] ?? null;

            if ($cachedReply !== null) {
                $this->sendControl(
                    $request->replyToWorker,
                    new WorkerAskReply($request->requestId, $cachedReply),
                    requestId: $request->requestId,
                    correlationId: $request->correlationId,
                    causationId: $request->causationId,
                );
            }

            return;
        }

        if ($state === WorkerAskState::Cancelled) {
            $this->sendInboundAskCancelled($request);

            return;
        }

        if ($state === WorkerAskState::InProgress) {
            $this->sendInboundAskAck($request);

            return;
        }

        $this->incomingAskState[$request->requestId] = WorkerAskState::InProgress;
        $this->incomingAskRequests[$request->requestId] = $request;

        $targetPath = (string) $request->targetPath;
        $ref = $this->localRefs[$targetPath] ?? null;

        if ($ref === null) {
            $this->incomingAskState[$request->requestId] = WorkerAskState::Cancelled;
            $this->incomingAskTerminalAt[$request->requestId] = time();
            $this->sendInboundAskCancelled($request);

            return;
        }

        $this->sendInboundAskAck($request);

        $replyRef = new WorkerReplyRef(
            requestId: $request->requestId,
            replyToWorker: $request->replyToWorker,
            path: $request->replyToPath,
            transport: $this->transport,
            onReply: function (object $reply) use ($request): bool {
                if (($this->incomingAskState[$request->requestId] ?? null) !== WorkerAskState::InProgress) {
                    return false;
                }

                $this->incomingAskState[$request->requestId] = WorkerAskState::Replied;
                $this->incomingAskReplies[$request->requestId] = $reply;
                $this->incomingAskTerminalAt[$request->requestId] = time();

                return true;
            },
        );

        $envelope = Envelope::of($request->payload, $request->replyToPath, $request->targetPath)
            ->withRequestId($request->requestId)
            ->withCorrelationId($request->correlationId)
            ->withCausationId($request->causationId)
            ->withSenderRef($replyRef);

        $ref->enqueueEnvelope($envelope);
    }

    private function handleWorkerAskReply(WorkerAskReply $reply): void
    {
        $entry = $this->pendingOutgoingAsks[$reply->requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry['slot']->resolve($reply->payload);
        $this->clearPendingOutgoingAsk($reply->requestId);
    }

    private function handleWorkerAskAck(WorkerAskAck $ack): void
    {
        $entry = $this->pendingOutgoingAsks[$ack->requestId] ?? null;

        if ($entry === null || $entry['acked']) {
            return;
        }

        $entry['acked'] = true;

        if ($entry['retryTimer'] !== null) {
            $entry['retryTimer']->cancel();
        }

        $entry['retryTimer'] = null;
        $this->pendingOutgoingAsks[$ack->requestId] = $entry;
    }

    private function handleWorkerAskCancel(WorkerAskCancel $cancel): void
    {
        $state = $this->incomingAskState[$cancel->requestId] ?? null;

        if ($state === null) {
            return;
        }

        if ($state === WorkerAskState::Replied) {
            return;
        }

        $this->incomingAskState[$cancel->requestId] = WorkerAskState::Cancelled;
        $this->incomingAskTerminalAt[$cancel->requestId] = time();
        $request = $this->incomingAskRequests[$cancel->requestId] ?? null;

        if ($request !== null) {
            $this->sendInboundAskCancelled($request);
        }
    }

    private function handleWorkerAskCancelled(WorkerAskCancelled $cancelled): void
    {
        $entry = $this->pendingOutgoingAsks[$cancelled->requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry['slot']->fail(new FutureCancelledException());
        $this->clearPendingOutgoingAsk($cancelled->requestId);
    }

    private function scheduleAskRetry(string $requestId): void
    {
        $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

        if ($entry === null || $entry['acked'] || $entry['slot']->isResolved() || $entry['retriesRemaining'] <= 0) {
            return;
        }

        $entry['retryTimer'] = $this->system->runtime()->scheduleOnce(
            Duration::millis(self::ASK_RETRY_INTERVAL_MILLIS),
            function () use ($requestId): void {
                $retryEntry = $this->pendingOutgoingAsks[$requestId] ?? null;

                if ($retryEntry === null || $retryEntry['acked'] || $retryEntry['slot']->isResolved()) {
                    return;
                }

                if ($retryEntry['retriesRemaining'] <= 0) {
                    return;
                }

                $retryEntry['retriesRemaining']--;
                $this->pendingOutgoingAsks[$requestId] = $retryEntry;
                $this->sendAskRequest($requestId);
                $this->scheduleAskRetry($requestId);
            },
        );

        $this->pendingOutgoingAsks[$requestId] = $entry;
    }

    private function sendAskRequest(string $requestId): void
    {
        $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $request = $entry['request'];

        $this->sendControl(
            $entry['targetWorker'],
            $request,
            requestId: $request->requestId,
            correlationId: $request->correlationId,
            causationId: $request->causationId,
        );
    }

    private function clearPendingOutgoingAsk(string $requestId): void
    {
        $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry['timeout']->cancel();

        if ($entry['retryTimer'] !== null) {
            $entry['retryTimer']->cancel();
        }

        unset($this->pendingOutgoingAsks[$requestId]);
    }

    private function sendInboundAskAck(WorkerAskRequest $request): void
    {
        $this->sendControl(
            $request->replyToWorker,
            new WorkerAskAck($request->requestId),
            requestId: $request->requestId,
            correlationId: $request->correlationId,
            causationId: $request->causationId,
        );
    }

    private function sendInboundAskCancelled(WorkerAskRequest $request): void
    {
        $this->sendControl(
            $request->replyToWorker,
            new WorkerAskCancelled($request->requestId),
            requestId: $request->requestId,
            correlationId: $request->correlationId,
            causationId: $request->causationId,
        );
    }

    private function sendOutgoingAskCancel(string $requestId): void
    {
        $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $request = $entry['request'];
        $this->sendControl(
            $entry['targetWorker'],
            new WorkerAskCancel($requestId),
            requestId: $request->requestId,
            correlationId: $request->correlationId,
            causationId: $request->causationId,
        );
    }

    private function pruneInboundTerminalState(): void
    {
        $cutoff = time() - self::INBOUND_TERMINAL_TTL_SECONDS;

        foreach ($this->incomingAskTerminalAt as $requestId => $terminalAt) {
            if ($terminalAt > $cutoff) {
                continue;
            }

            unset($this->incomingAskTerminalAt[$requestId]);
            unset($this->incomingAskState[$requestId]);
            unset($this->incomingAskRequests[$requestId]);
            unset($this->incomingAskReplies[$requestId]);
        }

        while (count($this->incomingAskTerminalAt) > self::INBOUND_TERMINAL_MAX_ENTRIES) {
            $oldestRequestId = array_key_first($this->incomingAskTerminalAt);

            if ($oldestRequestId === null) {
                break;
            }

            unset($this->incomingAskTerminalAt[$oldestRequestId]);
            unset($this->incomingAskState[$oldestRequestId]);
            unset($this->incomingAskRequests[$oldestRequestId]);
            unset($this->incomingAskReplies[$oldestRequestId]);
        }
    }

    private function sendControl(
        int $targetWorker,
        object $message,
        ?string $requestId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        $envelope = Envelope::of($message, ActorPath::root(), ActorPath::root());

        if ($requestId !== null) {
            $envelope = $envelope->withRequestId($requestId);
        }

        if ($correlationId !== null) {
            $envelope = $envelope->withCorrelationId($correlationId);
        }

        if ($causationId !== null) {
            $envelope = $envelope->withCausationId($causationId);
        }

        $this->transport->send($targetWorker, $envelope);
    }
}
