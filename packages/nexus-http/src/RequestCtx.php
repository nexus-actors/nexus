<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface RequestCtx
{
    public function request(): ServerRequestInterface;

    public function param(string $name): ?string;

    public function withParam(string $name, string $value): self;

    public function system(): ActorSystem;

    /** @return ActorRef<object>|null */
    public function actorFor(string $path): ?ActorRef;

    public function ask(string $path, object $message, ?Duration $timeout = null): mixed;

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
