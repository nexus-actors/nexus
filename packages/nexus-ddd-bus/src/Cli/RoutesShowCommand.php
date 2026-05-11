<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Cli;

use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Override;

/**
 * @psalm-api
 *
 * Service shape for the routes-show CLI subcommand. The runner package
 * (nexus-ddd-cli — TBD) supplies the argv parser and the shell shim;
 * this package ships only the service.
 */
final class RoutesShowCommand implements Command
{
    public function __construct(private readonly BusRegistry $registry, private readonly RoutingStrategy $strategy) {}

    /** @param list<string> $args */
    #[Override]
    public function run(array $args): string
    {
        if ($args === []) {
            return $this->renderAll();
        }

        /** @var class-string $messageClass */
        $messageClass = $args[0];

        return $this->renderOne($messageClass);
    }

    private function renderAll(): string
    {
        $output = "Registered command buses:\n";

        foreach ($this->registry->commandNames() as $name) {
            $output .= sprintf("  %s\n", $name);
        }

        return $output;
    }

    /** @param class-string $messageClass */
    private function renderOne(string $messageClass): string
    {
        $resolution = $this->strategy->resolve($messageClass)->getUnsafe();

        return sprintf(
            "%s → bus `%s` (resolved by %s)\n",
            $messageClass,
            $resolution->busName,
            $resolution->displayName(),
        );
    }
}
