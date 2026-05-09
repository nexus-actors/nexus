<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Aggregate;

use Monadial\Nexus\Psalm\Issue\AggregateEmitsOnlyEvents;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

use function count;
use function explode;
use function in_array;
use function strtolower;

/**
 * @internal
 *
 * Enforces v6 §6.4: aggregates emit domain events only. Direct calls to
 * `CommandBus`, `EventBus`, or `QueryBus` (and their enveloped variants)
 * from inside an aggregate are forbidden — cross-aggregate flow goes
 * through process managers reacting to events, not synchronous bus calls
 * from inside the aggregate boundary.
 *
 * Detection: any method-call analysis whose enclosing class extends
 * `AggregateRoot` AND whose declaring class is one of the bus interfaces.
 */
final class AggregateEmitsOnlyEventsRule implements AfterMethodCallAnalysisInterface
{
    private const string AGGREGATE_ROOT = 'monadial\nexus\ddd\core\aggregate\aggregateroot';

    private const array FORBIDDEN_BUS_INTERFACES = [
        'monadial\nexus\ddd\messaging\bus\commandbus',
        'monadial\nexus\ddd\messaging\bus\envelopedcommandbus',
        'monadial\nexus\ddd\messaging\bus\envelopedeventbus',
        'monadial\nexus\ddd\messaging\bus\envelopedquerybus',
        'monadial\nexus\ddd\messaging\bus\eventbus',
        'monadial\nexus\ddd\messaging\bus\querybus',
    ];

    #[Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $methodId = $event->getDeclaringMethodId();

        $parts = explode('::', $methodId, 2);

        if (count($parts) !== 2) {
            return;
        }

        [$calleeClass, $methodName] = $parts;

        if (!self::isForbiddenBusCall($event, $calleeClass)) {
            return;
        }

        $callerClass = $event->getStatementsSource()->getFQCLN();

        if ($callerClass === null) {
            return;
        }

        if (!self::isAggregate($event, $callerClass)) {
            return;
        }

        IssueBuffer::accepts(
            new AggregateEmitsOnlyEvents(
                $callerClass,
                $calleeClass . '::' . $methodName,
                new CodeLocation($event->getStatementsSource(), $event->getExpr()),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }

    private static function isForbiddenBusCall(AfterMethodCallAnalysisEvent $event, string $calleeClass): bool
    {
        $codebase = $event->getCodebase();
        $key = strtolower($calleeClass);

        if (in_array($key, self::FORBIDDEN_BUS_INTERFACES, true)) {
            return true;
        }

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        foreach (self::FORBIDDEN_BUS_INTERFACES as $bus) {
            if (isset($storage->class_implements[$bus]) || isset($storage->parent_interfaces[$bus])) {
                return true;
            }
        }

        return false;
    }

    private static function isAggregate(AfterMethodCallAnalysisEvent $event, string $callerClass): bool
    {
        $codebase = $event->getCodebase();
        $key = strtolower($callerClass);

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        return isset($storage->parent_classes[self::AGGREGATE_ROOT]);
    }
}
