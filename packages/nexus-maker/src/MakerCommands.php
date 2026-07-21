<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Symfony\Component\Console\Command\Command;

/**
 * @psalm-api entry point consumed by skeleton bin/console
 */
final class MakerCommands
{
    /**
     * @return list<Command>
     */
    public static function all(string $projectDir): array
    {
        return [
            new MakeActorCommand($projectDir),
            new MakeMessageCommand($projectDir),
        ];
    }
}
