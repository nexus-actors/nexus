<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Teaches Psalm that PHP 8.5's clone($object, [...]) returns the same type
 * as the first argument.
 *
 * Without this, Psalm infers `object` for clone() calls because it doesn't
 * yet understand the PHP 8.5 clone-with syntax.
 */
final class CloneWithReturnTypeProvider implements FunctionReturnTypeProviderInterface
{
    /** @return list<lowercase-string> */
    public static function getFunctionIds(): array
    {
        return ['clone'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return null;
        }

        return $event->getStatementsSource()
            ->getNodeTypeProvider()
            ->getType($args[0]->value);
    }
}
