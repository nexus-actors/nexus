<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Http\Routing\PathState;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface RequestCtx
{
    public function request(): ServerRequestInterface;

    public function param(string $name): ?string;

    public function withParam(string $name, string $value): self;

    public function pathState(): PathState;

    public function withPathState(PathState $state): self;

    public function system(): ActorSystem;

    /** @return ActorRef<object>|null */
    public function actorFor(string $path): ?ActorRef;

    /**
     * Synchronously ask an actor and return the resolved reply.
     *
     * Equivalent to askFuture(...)->await(). Convenience for the simple case.
     */
    public function ask(string $path, object $message, ?Duration $timeout = null): mixed;

    /**
     * Send an ask to an actor and return the unresolved Future.
     *
     * Use this for parallel composition: kick off multiple asks, then await each
     * at the response-construction site. The complete() directive recognises Future
     * return values and awaits them just before marshalling.
     *
     * @return Future<object>
     */
    public function askFuture(string $path, object $message, ?Duration $timeout = null): Future;

    /**
     * @psalm-suppress UndefinedClass MediaType / Marshaller arrive in Tasks 7-9.
     */
    public function marshallerFor(MediaType $type): Marshaller;

    /**
     * @psalm-suppress UndefinedClass MediaType arrives in Task 8.
     */
    public function negotiate(): MediaType;

    public function log(): LoggerInterface;
}
