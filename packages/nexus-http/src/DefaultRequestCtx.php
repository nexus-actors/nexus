<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @psalm-suppress UndefinedClass MarshallerRegistry arrives in Task 9.
 */
final readonly class DefaultRequestCtx implements RequestCtx
{
    /** @param array<string, string> $params */
    public function __construct(
        public ServerRequestInterface $request,
        public array $params,
        public ActorSystem $system,
        public MarshallerRegistry $registry,
        public LoggerInterface $logger,
    ) {}

    #[Override]
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    #[Override]
    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    #[Override]
    public function withParam(string $name, string $value): self
    {
        return new self(
            $this->request,
            [...$this->params, $name => $value],
            $this->system,
            $this->registry,
            $this->logger,
        );
    }

    #[Override]
    public function system(): ActorSystem
    {
        return $this->system;
    }

    /**
     * @return ActorRef<object>|null
     */
    #[Override]
    public function actorFor(string $path): ?ActorRef
    {
        return $this->system->actorFor($path);
    }

    #[Override]
    public function ask(string $path, object $message, ?Duration $timeout = null): mixed
    {
        return $this->askFuture($path, $message, $timeout)->await();
    }

    #[Override]
    public function askFuture(string $path, object $message, ?Duration $timeout = null): Future
    {
        $ref = $this->actorFor($path);

        if ($ref === null) {
            throw new RuntimeException("no actor at path '{$path}'");
        }

        /** @var Future<object> $future */
        $future = $ref->ask($message, $timeout ?? Duration::seconds(5));

        return $future;
    }

    /**
     * @psalm-suppress UndefinedClass MediaType / Marshaller arrive in Tasks 7-9.
     * @psalm-suppress MixedReturnStatement
     */
    #[Override]
    public function marshallerFor(MediaType $type): Marshaller
    {
        return $this->registry->byMediaType($type);
    }

    /**
     * @psalm-suppress UndefinedClass MediaType arrives in Task 8.
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedMethodCall
     */
    #[Override]
    public function negotiate(): MediaType
    {
        $accept = $this->request->getHeaderLine('Accept');

        return $this->registry->negotiate($accept)->mediaType();
    }

    #[Override]
    public function log(): LoggerInterface
    {
        return $this->logger;
    }
}
