<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Messaging;

use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Union;

use function count;
use function strtolower;

/**
 * @internal
 *
 * Shared signature-validation helpers for the messaging handler rules
 * (CommandHandlerSignatureRule, QueryHandlerSignatureRule,
 * EventListenerSignatureRule, OneCommandHandlerRule). Pure functions —
 * no instance state.
 */
final class HandlerSignatureInspector
{
    public const string MESSAGE_CONTEXT = 'monadial\nexus\ddd\messaging\context\messagecontext';

    /**
     * Validates a handler's __invoke signature against the expected shape:
     *   __invoke(MessageMarker $msg, ?MessageContext $ctx = null): Return
     *
     * Returns null on success, or a human-readable reason string on failure.
     *
     * @param string $messageMarker Lowercase FQCN of the marker the first
     *                              parameter must implement (e.g. Command, Query, DomainEvent).
     * @param bool $expectVoid True for command handlers and event listeners
     *                         (must return void); false for query handlers
     *                         (must return non-void).
     */
    public static function validateInvoke(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $messageMarker,
        bool $expectVoid,
    ): ?string {
        $method = $storage->methods['__invoke'] ?? null;

        if ($method === null) {
            return 'no public __invoke() method';
        }

        $params = $method->params;
        $count = count($params);

        if ($count < 1 || $count > 2) {
            return 'expected 1 or 2 parameters, got ' . $count;
        }

        $firstType = self::firstNamedObjectFqcn($params[0]->signature_type);

        if ($firstType === null) {
            return 'first parameter must be a typed object';
        }

        if (!self::implementsMarker($codebase, $firstType, $messageMarker)) {
            return sprintf('first parameter %s must implement %s', $firstType, self::shortName($messageMarker));
        }

        if ($count === 2) {
            $secondType = self::firstNamedObjectFqcn($params[1]->signature_type);

            if ($secondType === null || strtolower($secondType) !== self::MESSAGE_CONTEXT) {
                return 'second parameter must be MessageContext';
            }
        }

        $returnType = $method->signature_return_type ?? $method->return_type;

        if ($expectVoid) {
            if ($returnType === null) {
                return 'return type must be void';
            }

            foreach ($returnType->getAtomicTypes() as $atomic) {
                if (!$atomic instanceof TVoid) {
                    return 'return type must be void';
                }
            }
        } else {
            if ($returnType !== null) {
                foreach ($returnType->getAtomicTypes() as $atomic) {
                    if ($atomic instanceof TVoid) {
                        return 'return type must not be void — query handlers must return TResult';
                    }
                }
            }
        }

        return null;
    }

    public static function firstNamedObjectFqcn(?Union $type): ?string
    {
        if ($type === null) {
            return null;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                return $atomic->value;
            }
        }

        return null;
    }

    public static function implementsMarker(Codebase $codebase, string $className, string $markerLower): bool
    {
        $key = strtolower($className);

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        return isset($storage->class_implements[$markerLower]);
    }

    private static function shortName(string $fqcnLower): string
    {
        $parts = explode('\\', $fqcnLower);
        $last = $parts[count($parts) - 1];

        return ucfirst($last);
    }
}
