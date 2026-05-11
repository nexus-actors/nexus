<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryIdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Async-profile idempotency: the second dispatch with the same key
 * short-circuits before the handler runs and returns `Accepted` per the
 * "already handled" contract. Sync profile bypasses the slot entirely per
 * H6, so this scenario only meaningfully exercises Async/Actor.
 */
#[CoversClass(SyncCommandBus::class)]
final class IdempotencyShortCircuitSmokeTest extends TestCase
{
    #[Test]
    public function secondDispatchWithSameKeyDoesNotInvokeHandler(): void
    {
        $harness = new PipelineHarness();
        $harness->profile = Profile::Async;
        $harness->idempotencyStore = new InMemoryIdempotencyStore();
        $handler = new IdempotencyShortCircuitHandler();
        $harness->register(IdempotencyShortCircuitCommand::class, IdempotencyShortCircuitHandler::class, $handler);
        $bus = $harness->build();

        $firstResult = $bus->tryDispatch(new IdempotencyShortCircuitCommand(clientRequestId: 'req-1'));
        $secondResult = $bus->tryDispatch(new IdempotencyShortCircuitCommand(clientRequestId: 'req-1'));

        self::assertTrue($firstResult->isRight());
        self::assertInstanceOf(Accepted::class, $firstResult->get());
        self::assertTrue($secondResult->isRight());

        self::assertCount(
            1,
            $handler->invocations,
            'handler must run exactly once even though the bus is dispatched twice with the same key',
        );
    }
}

#[IdempotencyKey(field: 'clientRequestId')]
final readonly class IdempotencyShortCircuitCommand implements Command
{
    public function __construct(public string $clientRequestId) {}
}

final class IdempotencyShortCircuitHandler implements CommandHandler
{
    /** @var list<IdempotencyShortCircuitCommand> */
    public array $invocations = [];

    public function __invoke(IdempotencyShortCircuitCommand $command): void
    {
        $this->invocations[] = $command;
    }
}
