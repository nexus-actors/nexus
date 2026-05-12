<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use LogicException;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use ReflectionMethod;
use ReflectionObject;

use function class_exists;
use function explode;
use function method_exists;
use function sprintf;
use function str_contains;

/**
 * @psalm-api
 *
 * Resolves the runtime subject from #[Authorize(subject:)]. The string form
 * names a property on the message class; the callable form references a
 * public static method `'Class::method'` that receives ($message,
 * MessageContext) and returns the subject.
 */
final class SubjectResolver
{
    public function resolve(object $message, string $subjectSpec, MessageContext $ctx): mixed
    {
        if (str_contains($subjectSpec, '::')) {
            /** @var array{0: string, 1: string} $parts */
            $parts = explode('::', $subjectSpec, 2);
            [$class, $method] = $parts;

            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new LogicException(sprintf(
                    'Subject spec `%s` looks like a `Class::method` callable but the class or method does not exist.',
                    $subjectSpec,
                ));
            }

            $reflection = new ReflectionMethod($class, $method);

            if (!$reflection->isPublic() || !$reflection->isStatic()) {
                throw new LogicException(sprintf(
                    'Subject spec `%s` must reference a public static method.',
                    $subjectSpec,
                ));
            }

            /** @var mixed $value */
            $value = $reflection->invoke(null, $message, $ctx);

            return $value;
        }

        $reflection = new ReflectionObject($message);

        if (!$reflection->hasProperty($subjectSpec)) {
            throw new LogicException(sprintf(
                'Property `%s` does not exist on `%s`. The #[Authorize(subject:)] string form names a property on the message class. Use the `Class::method` form for callable subjects.',
                $subjectSpec,
                $message::class,
            ));
        }

        return $reflection->getProperty($subjectSpec)->getValue($message);
    }
}
