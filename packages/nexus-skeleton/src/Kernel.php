<?php

declare(strict_types=1);

namespace App;

use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Runtime;

/**
 * Application bootstrap glue.
 *
 * Extend this class to register actors, attach middleware, and configure
 * integrations before handing off to NexusApp::run().
 */
final class Kernel
{
    public static function create(Runtime $runtime): void
    {
        NexusApp::create('my-app')
            ->actor('example', Props::fromBehavior(\App\Actor\ExampleActor::behavior()))
            ->run($runtime);
    }
}
