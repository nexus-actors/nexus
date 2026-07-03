<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\UntypedActorRefInjection;
use Override;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Union;

use function array_merge;
use function strtolower;

final class UntypedActorRefInjectionRule implements AfterFunctionLikeAnalysisInterface
{
    private const string ACTOR_REF = 'Monadial\Nexus\Core\Actor\ActorRef';
    private const string DEAD_LETTER_REF = 'Monadial\Nexus\Core\Actor\DeadLetterRef';

    /** @var list<string> */
    private static array $excludedRefs = [self::DEAD_LETTER_REF];

    /**
     * @psalm-api
     * @param list<string> $additionalExcludedRefs
     */
    public static function configure(array $additionalExcludedRefs): void
    {
        self::$excludedRefs = [self::DEAD_LETTER_REF, ...$additionalExcludedRefs];
    }

    /**
     * @psalm-api
     * @return list<string>
     */
    public static function excludedRefs(): array
    {
        return self::$excludedRefs;
    }

    #[Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getFunctionlikeStorage();
        $suppressed = $event->getStatementsSource()->getSuppressedIssues();

        if ($storage instanceof MethodStorage) {
            $ownerName = ($storage->defining_fqcln !== null ? $storage->defining_fqcln . '::' : '')
                . ($storage->cased_name ?? '');
        } else {
            $ownerName = $storage->cased_name ?? 'closure';
        }

        self::checkParams($storage, $ownerName, $event->getCodebase(), $suppressed);

        return null;
    }

    /** @param array<string> $suppressed */
    public static function report(string $subject, CodeLocation $location, array $suppressed): void
    {
        IssueBuffer::accepts(new UntypedActorRefInjection($subject, $location), $suppressed);
    }

    public static function unionViolates(Codebase $codebase, Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (self::atomicViolates($codebase, $atomic)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string> $suppressed */
    private static function checkParams(
        FunctionLikeStorage $storage,
        string $ownerName,
        Codebase $codebase,
        array $suppressed,
    ): void {
        foreach ($storage->params as $param) {
            if ($param->promoted_property) {
                continue;
            }

            if ($param->type === null || !self::unionViolates($codebase, $param->type)) {
                continue;
            }

            $location = $param->type_location ?? $param->location;

            if ($location === null) {
                continue;
            }

            self::report(
                'ActorRef injection for parameter $' . $param->name . ' of ' . $ownerName,
                $location,
                array_merge($suppressed, $storage->suppressed_issues),
            );
        }
    }

    private static function atomicViolates(Codebase $codebase, Atomic $atomic): bool
    {
        if ($atomic instanceof TGenericObject) {
            if (self::isCheckedActorRef($codebase, $atomic->value)) {
                $messageType = $atomic->type_params[0];

                foreach ($messageType->getAtomicTypes() as $inner) {
                    // Exactly `object` — TObject is its own class in Psalm 6;
                    // TNamedObject does not extend TObject, so instanceof TObject
                    // fires only on bare `object`, not named classes or templates.
                    if ($inner instanceof TObject) {
                        return true;
                    }
                }

                return false;
            }

            return self::anyParamViolates($codebase, $atomic->type_params);
        }

        if ($atomic instanceof TNamedObject) {
            return self::isCheckedActorRef($codebase, $atomic->value);
        }

        if ($atomic instanceof TArray || $atomic instanceof TIterable) {
            return self::anyParamViolates($codebase, $atomic->type_params);
        }

        if ($atomic instanceof TKeyedArray) {
            foreach ($atomic->properties as $propertyType) {
                if (self::unionViolates($codebase, $propertyType)) {
                    return true;
                }
            }

            if ($atomic->fallback_params !== null) {
                return self::anyParamViolates($codebase, $atomic->fallback_params);
            }

            return false;
        }

        return false;
    }

    /** @param array<array-key, Union> $typeParams */
    private static function anyParamViolates(Codebase $codebase, array $typeParams): bool
    {
        foreach ($typeParams as $typeParam) {
            if (self::unionViolates($codebase, $typeParam)) {
                return true;
            }
        }

        return false;
    }

    private static function isCheckedActorRef(Codebase $codebase, string $fqClassName): bool
    {
        if (!self::isActorRef($codebase, $fqClassName)) {
            return false;
        }

        foreach (self::$excludedRefs as $excluded) {
            if (strtolower($fqClassName) === strtolower($excluded)) {
                return false;
            }

            if ($codebase->classExists($fqClassName) && $codebase->classExtends($fqClassName, $excluded)) {
                return false;
            }
        }

        return true;
    }

    private static function isActorRef(Codebase $codebase, string $fqClassName): bool
    {
        if (strtolower($fqClassName) === strtolower(self::ACTOR_REF)) {
            return true;
        }

        if ($codebase->classExists($fqClassName)) {
            return $codebase->classImplements($fqClassName, self::ACTOR_REF);
        }

        if ($codebase->interfaceExists($fqClassName)) {
            return $codebase->interfaceExtends($fqClassName, self::ACTOR_REF);
        }

        return false;
    }
}
