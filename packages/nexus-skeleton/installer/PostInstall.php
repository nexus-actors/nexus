<?php

declare(strict_types=1);

namespace NexusSkeleton\Installer;

use Composer\Script\Event;

final class PostInstall
{
    public static function run(Event $event): void
    {
        $io = $event->getIO();
        $configurator = new ProjectConfigurator(
            io: $io,
            projectRoot: (string) getcwd(),
        );
        $configurator->configure();
    }
}
