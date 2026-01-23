<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Override;

final readonly class CaptureFixtureMsg {
}

/** @implements ActorHandler<CaptureFixtureMsg> */
final readonly class CaptureFixtureHandler implements ActorHandler
{
    /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}

final class ClosureCaptureFixture
{
    /** Good: value capture — safe. */
    public function factoryWithValueCapture(): void
    {
        $name = 'test';

        /** @psalm-suppress InvalidArgument, UnusedVariable */
        $props = Props::fromFactory(static function () use ($name): ActorHandler {
            echo $name;

            return new CaptureFixtureHandler();
        });
    }

    /** Bad: by-reference capture — shared mutable state. */
    public function factoryWithRefCapture(): void
    {
        $counter = 0;

        /** @psalm-suppress InvalidArgument, UnusedVariable */
        $props = Props::fromFactory(static function () use (&$counter): ActorHandler {
            $counter++;

            return new CaptureFixtureHandler();
        });
    }

    /** Good: arrow function — implicit value capture, always safe. */
    public function factoryWithArrowFunction(): void
    {
        /** @psalm-suppress InvalidArgument, UnusedVariable */
        $props = Props::fromFactory(static fn (): ActorHandler => new CaptureFixtureHandler());
    }
}
