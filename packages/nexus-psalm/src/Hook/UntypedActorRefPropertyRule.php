<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Override;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

use function array_merge;

/**
 * Flags properties (including promoted constructor params) that hold a bare
 * ActorRef or an ActorRef<object> — both defeat typed messaging.
 *
 * Promoted params: Psalm copies the constructor @param generic into the
 * property storage, so UarTypedPromotedService (with @param ActorRef<T>)
 * stays clean while UarBarePromotedService fires.  The function-like hook
 * already skips promoted params (promoted_property guard in checkParams),
 * so there is no double-reporting.
 *
 * Interface/abstract method params are intentionally NOT walked here.
 * Despite what the original task brief assumed, Psalm's function-like hook
 * (AfterFunctionLikeAnalysisInterface) DOES fire for bodyless interface
 * methods — confirmed empirically: UarSinkRegistry::register was already
 * caught with 7 issues before this class existed.  Walking those methods
 * again would cause double-reporting.
 */
final class UntypedActorRefPropertyRule implements AfterClassLikeAnalysisInterface
{
    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $classStorage = $event->getClasslikeStorage();
        $codebase = $event->getCodebase();
        $suppressed = $event->getStatementsSource()->getSuppressedIssues();

        foreach ($classStorage->properties as $propertyName => $property) {
            if ($property->is_static) {
                continue;
            }

            if ($property->type === null || !UntypedActorRefInjectionRule::unionViolates($codebase, $property->type)) {
                continue;
            }

            $location = $property->type_location ?? $property->location ?? $property->stmt_location;

            if ($location === null) {
                continue;
            }

            UntypedActorRefInjectionRule::report(
                'ActorRef injection for property ' . $classStorage->name . '::$' . $propertyName,
                $location,
                array_merge($suppressed, $property->suppressed_issues),
            );
        }

        return null;
    }
}
