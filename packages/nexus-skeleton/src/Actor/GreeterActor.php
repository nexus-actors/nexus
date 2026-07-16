<?php

declare(strict_types=1);

namespace App\Actor;

use App\Message\Greet;
use App\Support\Recorder;
use Monadial\Nexus\App\AsActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Override;

/**
 * @implements ActorHandler<object>
 *
 * @psalm-api spawned by the Kernel via #[AsActor] autoconfiguration
 */
#[AsActor('greeter')]
final readonly class GreeterActor implements ActorHandler
{
    public function __construct(private Recorder $recorder) {}

    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof Greet) {
            $this->recorder->greeted[] = $message->name;
        }

        return Behavior::same();
    }
}
