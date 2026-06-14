<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function bin2hex;
use function random_bytes;

/**
 * @psalm-api
 *
 * Fluent factory that spawns a LogActor on the given ActorSystem and
 * returns a PSR-3 LoggerInterface bound to it. Typical usage:
 *
 *   $logger = NexusLogger::create($system, 'app')
 *       ->minLevel(Level::Debug)
 *       ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
 *       ->handler(new FileHandler('/var/log/app.log', new JsonFormatter()))
 *       ->build();
 *
 *   $logger->info('user logged in', ['userId' => 42]);
 */
final class NexusLogger
{
    private Level $minLevel = Level::Debug;

    /** @var list<Handler> */
    private array $handlers = [];

    /** @var list<RecordProcessor> */
    private array $processors = [];

    private function __construct(private readonly ActorSystem $system, private string $channel = 'app') {}

    public static function create(ActorSystem $system, string $channel = 'app'): self
    {
        return new self($system, $channel);
    }

    public function channel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function minLevel(Level $level): self
    {
        $this->minLevel = $level;

        return $this;
    }

    public function handler(Handler $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    public function processor(RecordProcessor $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    public function build(): LoggerInterface
    {
        if ($this->handlers === []) {
            throw new RuntimeException(
                'NexusLogger requires at least one handler. Add one with ->handler(new ConsoleHandler(...)).',
            );
        }

        $handlers = $this->handlers;
        /** @psalm-suppress InvalidArgument — Props::fromStatefulFactory accepts the LogActor template params */
        $props = Props::fromStatefulFactory(static fn(): LogActor => new LogActor($handlers));
        $name = 'nexus-logger-' . bin2hex(random_bytes(4));

        $ref = $this->system->spawn($props, $name);

        /** @psalm-suppress ArgumentTypeCoercion — ActorRef<object> from spawn is the wire type; the sink only receives Record messages by construction. */
        return new Logger($ref, $this->channel, $this->minLevel, $this->processors);
    }
}
