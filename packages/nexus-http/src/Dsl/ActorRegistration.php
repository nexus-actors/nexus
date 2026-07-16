<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

/**
 * @psalm-api
 *
 * Fluent setter returned by HttpApp::actor() / perRequestActor(). Mutates
 * the entry in the registry. The terminal accessors freeze into
 * ActorRegistrationEntry at compile time.
 */
final class ActorRegistration
{
    private ActorMode $mode;

    private ?SupervisionStrategy $supervision = null;

    private ?MailboxConfig $mailbox = null;

    public function __construct(private readonly string $name, ActorMode $initialMode)
    {
        $this->mode = $initialMode;
    }

    public function currentMailbox(): ?MailboxConfig
    {
        return $this->mailbox;
    }

    public function currentMode(): ActorMode
    {
        return $this->mode;
    }

    public function currentSupervision(): ?SupervisionStrategy
    {
        return $this->supervision;
    }

    public function mode(ActorMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function poolSingleton(): self
    {
        return $this->mode(ActorMode::PoolSingleton);
    }

    public function withMailbox(MailboxConfig $config): self
    {
        $this->mailbox = $config;

        return $this;
    }

    public function withSupervision(SupervisionStrategy $strategy): self
    {
        $this->supervision = $strategy;

        return $this;
    }

    public function workerLocal(): self
    {
        return $this->mode(ActorMode::WorkerLocal);
    }
}
