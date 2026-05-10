<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Closure;
use LogicException;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use ReflectionObject;

use function is_callable;
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
            if (!is_callable($subjectSpec)) {
                throw new LogicException(sprintf(
                    'Subject spec `%s` looks like a `Class::method` callable but is not callable. The method must exist and be public+static.',
                    $subjectSpec,
                ));
            }

            $callable = Closure::fromCallable($subjectSpec);

            return $callable($message, $ctx);
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
