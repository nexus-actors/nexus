<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use NoDiscard;
use Override;

use function array_unique;
use function array_values;
use function count;
use function sprintf;

/**
 * @psalm-api
 *
 * Walks sub-strategies in registration order; first `Some(...)` wins. Per
 * umbrella spec §8.2, the standard order is `ExplicitOnly →
 * AttributeBased → NamespacePattern → fallback to default`.
 *
 * `withStrategy(...)` appends a new strategy or inserts before another by
 * class name (H8 adopter extension hook). `validate(handlerClasses)`
 * enumerates each class and throws `DuplicateRoutingException` when
 * multiple strategies resolve different bus names — useful at boot to
 * catch misconfiguration before the first dispatch.
 */
final readonly class Composite implements RoutingStrategy
{
    /** @param list<RoutingStrategy> $strategies */
    public function __construct(private array $strategies, private string $defaultBusName) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->strategies as $strategy) {
            $resolution = $strategy->resolve($messageClass);

            if ($resolution->isSome()) {
                return $resolution;
            }
        }

        return Option::some(new RoutingResolution($this->defaultBusName, self::class));
    }

    /**
     * @param class-string<RoutingStrategy>|null $before  Insert before this strategy class; null = append.
     */
    #[NoDiscard('withStrategy returns a new Composite — the original is unchanged')]
    public function withStrategy(RoutingStrategy $strategy, ?string $before = null): self
    {
        if ($before === null) {
            return new self([...$this->strategies, $strategy], $this->defaultBusName);
        }

        $output = [];

        foreach ($this->strategies as $existing) {
            if ($existing::class === $before) {
                $output[] = $strategy;
            }

            $output[] = $existing;
        }

        return new self($output, $this->defaultBusName);
    }

    /**
     * @param iterable<class-string> $handlerClasses
     * @throws DuplicateRoutingException when two strategies resolve different busNames for the same class.
     */
    public function validate(iterable $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            /** @var array<class-string, string> $resolutions */
            $resolutions = [];

            foreach ($this->strategies as $strategy) {
                $resolution = $strategy->resolve($handlerClass);

                if ($resolution->isSome()) {
                    $resolutions[$strategy::class] = $resolution->getUnsafe()->busName;
                }
            }

            $unique = array_unique(array_values($resolutions));

            if (count($unique) > 1) {
                $entries = [];

                foreach ($resolutions as $strategyClass => $busName) {
                    $entries[] = sprintf('%s: %s', $strategyClass, $busName);
                }

                throw DuplicateRoutingException::for($handlerClass, $entries);
            }
        }
    }
}
