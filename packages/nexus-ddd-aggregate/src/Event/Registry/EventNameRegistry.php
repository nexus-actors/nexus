<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Registry;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\Attribute\Event;
use Monadial\Nexus\Ddd\Aggregate\Exception\EventNameCollisionException;
use ReflectionClass;

/**
 * @psalm-api
 *
 * Boot-time registry of `(eventName, version) -> class-string` mappings.
 * Built once at application boot from a list of candidate `DomainEvent`
 * classes; `scan()` validates `(name, version)` uniqueness and throws
 * `EventNameCollisionException` on duplicate. Apps wire the registry
 * into the serializer + upcaster pipeline.
 */
final class EventNameRegistry
{
    /**
     * @param array<string, class-string> $byNameVersion key = "name@version"
     */
    private function __construct(private readonly array $byNameVersion) {}

    /**
     * @param iterable<class-string> $candidateClasses
     *
     * @throws EventNameCollisionException
     */
    public static function scan(iterable $candidateClasses): self
    {
        $byNameVersion = [];

        foreach ($candidateClasses as $class) {
            $reflection = new ReflectionClass($class);
            $attrs = $reflection->getAttributes(Event::class);

            if ($attrs === []) {
                continue;
            }

            $event = $attrs[0]->newInstance();
            $key = $event->name . '@' . $event->version;

            if (isset($byNameVersion[$key])) {
                throw EventNameCollisionException::between($event->name, $byNameVersion[$key], $class);
            }

            $byNameVersion[$key] = $class;
        }

        return new self($byNameVersion);
    }

    /**
     * @return Option<class-string>
     */
    public function classFor(string $name, int $version): Option
    {
        $key = $name . '@' . $version;

        if (! isset($this->byNameVersion[$key])) {
            return Option::none();
        }

        return Option::some($this->byNameVersion[$key]);
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->byNameVersion;
    }
}
