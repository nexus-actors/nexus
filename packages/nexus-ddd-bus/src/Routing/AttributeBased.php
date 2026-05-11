<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\OnBus;
use Override;
use ReflectionClass;

/**
 * @psalm-api
 *
 * Reads the message class's `#[OnBus(name:)]` attribute, if any. A class
 * without the attribute yields `None`, deferring to the next strategy in
 * the Composite chain.
 */
final class AttributeBased implements RoutingStrategy
{
    #[Override]
    public function resolve(string $messageClass): Option
    {
        $attrs = (new ReflectionClass($messageClass))->getAttributes(OnBus::class);

        if ($attrs === []) {
            return Option::none();
        }

        $name = $attrs[0]->newInstance()->name;

        return Option::some(new RoutingResolution($name, self::class));
    }
}
