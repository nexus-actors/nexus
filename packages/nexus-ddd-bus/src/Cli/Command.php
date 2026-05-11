<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Cli;

/**
 * @psalm-api
 *
 * Minimal command shape. Adapter packages (nexus-ddd-cli — TBD, or
 * nexus-app) supply the shell shim that wires argv → Command::run.
 */
interface Command
{
    /** @param list<string> $args */
    public function run(array $args): string;
}
