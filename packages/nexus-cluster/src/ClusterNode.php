<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use Monadial\Nexus\Cluster\Protocol\RemoteAskAck;
use Monadial\Nexus\Cluster\Protocol\RemoteAskCancel;
use Monadial\Nexus\Cluster\Protocol\RemoteAskCancelled;
use Monadial\Nexus\Cluster\Protocol\RemoteAskReply;
use Monadial\Nexus\Cluster\Protocol\RemoteAskRequest;
use Monadial\Nexus\Cluster\Remote\RemoteAskState;
use Monadial\Nexus\Cluster\Remote\RemoteReplyRef;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Transport\Transport;
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

/**
 * @psalm-api
 *
 * Per-worker cluster coordinator.
 *
 * Owns the ActorSystem, routes messages via the hash ring, and handles
 * incoming transport messages. Each worker process runs exactly one ClusterNode.
 */
final class ClusterNode
{
    private const int ASK_RETRY_MAX_ATTEMPTS = 3;
    private const int ASK_RETRY_INTERVAL_MILLIS = 50;
    private const int INBOUND_TERMINAL_TTL_SECONDS = 60;
    private const int INBOUND_TERMINAL_MAX_ENTRIES = 10_000;

    /** @var array<string, LocalActorRef<object>> */
    private array $localRefs = [];

    /** @var array<string, array{
     *     acked: bool,
     *     request: RemoteAskRequest,
     *     retriesRemaining: int,
     *     retryTimer: Cancellable|null,
     *     slot: FutureSlot<object>,
     *     timeout: Cancellable,
     *     target: ActorPath,
     *     targetWorker: int
     * }> */
    private array $pendingOutgoingAsks = [];

    /** @var array<string, RemoteAskState> */
    private array $incomingAskState = [];

    /** @var array<string, RemoteAskRequest> */
    private array $incomingAskRequests = [];

    /** @var array<string, object> */
    private array $incomingAskReplies = [];

    /** @var array<string, int> */
    private array $incomingAskTerminalAt = [];

    public function __construct(
        private readonly int $workerId,
        private readonly ActorSystem $system,
        private readonly Transport $transport,
        private readonly ConsistentHashRing $ring,
        private readonly ClusterSerializer $serializer,
        private readonly ActorDirectory $directory,
    ) {}

    /**
     * Spawn an actor, routing to local or remote based on the hash ring.
     *
     * If the hash ring assigns the actor name to this worker, the actor is
     * spawned locally via the ActorSystem. Otherwise, a RemoteActorRef is
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

        /** @var RemoteActorRef<T> $remoteRef */
        $remoteRef = new RemoteActorRef(
            $path,
            $ownerWorker,
            $this->transport,
            $this->serializer,
            $this->directory,
            $this->askRemote(...),
        );

        return $remoteRef;
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

        return new RemoteActorRef(
            ActorPath::fromString($path),
            $workerId,
            $this->transport,
            $this->serializer,
            $this->directory,
            $this->askRemote(...),
        );
    }

    /**
     * Start listening for incoming transport messages.
     *
     * Incoming envelopes are deserialized and delivered to the target local
     * actor's mailbox via enqueueEnvelope(), preserving the original sender path.
     */
    public function start(): void
    {
        $this->transport->listen(function (string $data): void {
            $envelope = $this->serializer->deserialize($data);

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
        $replyPath = ActorPath::fromString('/temp/remote-ask-' . $requestId);
        $request = new RemoteAskRequest(
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
            function () use ($requestId, $slot, $targetPath, $timeout, $targetWorker): void {
                if ($slot->isResolved()) {
                    return;
                }

                $slot->fail(new AskTimeoutException($targetPath, $timeout));
                $this->clearPendingOutgoingAsk($requestId);
                $this->sendControl($targetWorker, new RemoteAskCancel($requestId));
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

        $slot->onCancel(function () use ($requestId, $targetWorker): void {
            $entry = $this->pendingOutgoingAsks[$requestId] ?? null;

            if ($entry === null) {
                return;
            }

            $this->clearPendingOutgoingAsk($requestId);
            $this->sendControl($targetWorker, new RemoteAskCancel($requestId));
        });

        $this->sendAskRequest($requestId);
        $this->scheduleAskRetry($requestId);

        /** @var Future<R> */
        return new Future($slot);
    }

    private function handleControlMessage(Envelope $envelope): bool
    {
        $message = $envelope->message;

        if ($message instanceof RemoteAskRequest) {
            $this->handleRemoteAskRequest($message);

            return true;
        }

        if ($message instanceof RemoteAskReply) {
            $this->handleRemoteAskReply($message);

            return true;
        }

        if ($message instanceof RemoteAskCancel) {
            $this->handleRemoteAskCancel($message);

            return true;
        }

        if ($message instanceof RemoteAskCancelled) {
            $this->handleRemoteAskCancelled($message);

            return true;
        }

        if ($message instanceof RemoteAskAck) {
            $this->handleRemoteAskAck($message);

            return true;
        }

        return false;
    }

    private function handleRemoteAskRequest(RemoteAskRequest $request): void
    {
        $this->pruneInboundTerminalState();
        $state = $this->incomingAskState[$request->requestId] ?? null;

        if ($state === RemoteAskState::Replied) {
            $cachedReply = $this->incomingAskReplies[$request->requestId] ?? null;

            if ($cachedReply !== null) {
                $this->sendControl(
                    $request->replyToWorker,
                    new RemoteAskReply($request->requestId, $cachedReply),
                    requestId: $request->requestId,
                    correlationId: $request->correlationId,
                    causationId: $request->causationId,
                );
            }

            return;
        }

        if ($state === RemoteAskState::Cancelled) {
            $this->sendControl($request->replyToWorker, new RemoteAskCancelled($request->requestId));

            return;
        }

        if ($state === RemoteAskState::InProgress) {
            $this->sendControl($request->replyToWorker, new RemoteAskAck($request->requestId));

            return;
        }

        $this->incomingAskState[$request->requestId] = RemoteAskState::InProgress;
        $this->incomingAskRequests[$request->requestId] = $request;

        $targetPath = (string) $request->targetPath;
        $ref = $this->localRefs[$targetPath] ?? null;

        if ($ref === null) {
            $this->incomingAskState[$request->requestId] = RemoteAskState::Cancelled;
            $this->incomingAskTerminalAt[$request->requestId] = time();
            $this->sendControl($request->replyToWorker, new RemoteAskCancelled($request->requestId));

            return;
        }

        $this->sendControl($request->replyToWorker, new RemoteAskAck($request->requestId));

        $replyRef = new RemoteReplyRef(
            requestId: $request->requestId,
            replyToWorker: $request->replyToWorker,
            path: $request->replyToPath,
            transport: $this->transport,
            serializer: $this->serializer,
            onReply: function (object $reply) use ($request): bool {
                if (($this->incomingAskState[$request->requestId] ?? null) !== RemoteAskState::InProgress) {
                    return false;
                }

                $this->incomingAskState[$request->requestId] = RemoteAskState::Replied;
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

    private function handleRemoteAskReply(RemoteAskReply $reply): void
    {
        $entry = $this->pendingOutgoingAsks[$reply->requestId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry['slot']->resolve($reply->payload);
        $this->clearPendingOutgoingAsk($reply->requestId);
    }

    private function handleRemoteAskAck(RemoteAskAck $ack): void
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

    private function handleRemoteAskCancel(RemoteAskCancel $cancel): void
    {
        $state = $this->incomingAskState[$cancel->requestId] ?? null;

        if ($state === null) {
            return;
        }

        if ($state === RemoteAskState::Replied) {
            return;
        }

        $this->incomingAskState[$cancel->requestId] = RemoteAskState::Cancelled;
        $this->incomingAskTerminalAt[$cancel->requestId] = time();
        $request = $this->incomingAskRequests[$cancel->requestId] ?? null;

        if ($request !== null) {
            $this->sendControl($request->replyToWorker, new RemoteAskCancelled($cancel->requestId));
        }
    }

    private function handleRemoteAskCancelled(RemoteAskCancelled $cancelled): void
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

        $this->transport->send($targetWorker, $this->serializer->serialize($envelope));
    }

}
