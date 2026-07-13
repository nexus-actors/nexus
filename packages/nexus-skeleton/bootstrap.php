<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

NexusApp::create('my-app')
    ->actor('hello', Props::fromBehavior(
        Behavior::receive(static function ($ctx, $msg): Behavior {
            $ctx->log()->info('received', ['type' => $msg::class]);

            return Behavior::same();
        })
    ))
    ->run(new FiberRuntime());
