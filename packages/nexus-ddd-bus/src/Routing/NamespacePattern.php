<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use NoDiscard;
use Override;

use function fnmatch;

use const FNM_NOESCAPE;

/**
 * @psalm-api
 *
 * Matches the FQCN against glob-style patterns (e.g. `App\Orders\*`),
 * first match wins. The `FNM_NOESCAPE` flag disables backslash escaping
 * so namespace separators in the pattern are matched literally.
 */
final class NamespacePattern implements RoutingStrategy
{
    /** @var list<array{busName: string, pattern: string}> */
    private array $patterns = [];

    #[NoDiscard('namespace() returns this — assign or chain')]
    public function namespace(string $pattern, string $busName): self
    {
        $this->patterns[] = ['busName' => $busName, 'pattern' => $pattern];

        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->patterns as $entry) {
            if (fnmatch($entry['pattern'], $messageClass, FNM_NOESCAPE)) {
                return Option::some(new RoutingResolution($entry['busName'], self::class));
            }
        }

        return Option::none();
    }
}
