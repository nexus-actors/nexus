<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Override;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

use function str_contains;

/**
 * Suppresses false-positive Psalm type-narrowing errors that arise when
 * narrowing a Behavior<T> abstract generic to one of its concrete subclasses.
 *
 * Root cause: Psalm's TemplateStandinTypeReplacer cannot reconcile T from a
 * concrete subclass scope (e.g. ReceiveBehavior<T>) with T from the abstract
 * parent scope (Behavior<T:ActorCell>), so it falls back to mixed, which fails
 * the containment check and fires DocblockTypeContradiction / TypeDoesNotContainType.
 *
 * This hook fires before each issue is recorded and suppresses only the
 * specific cases where both the abstract Behavior<T> parent and one of the
 * known concrete subclasses appear in the issue message.
 *
 * @psalm-api
 */
final class BehaviorSubclassNarrowingHook implements BeforeAddIssueInterface
{
    private const array BEHAVIOR_SUBCLASSES = [
        'Monadial\\Nexus\\Core\\Actor\\EmptyBehavior',
        'Monadial\\Nexus\\Core\\Actor\\ReceiveBehavior',
        'Monadial\\Nexus\\Core\\Actor\\SameBehavior',
        'Monadial\\Nexus\\Core\\Actor\\SetupBehavior',
        'Monadial\\Nexus\\Core\\Actor\\StoppedBehavior',
        'Monadial\\Nexus\\Core\\Actor\\SupervisedBehavior',
        'Monadial\\Nexus\\Core\\Actor\\UnhandledBehavior',
        'Monadial\\Nexus\\Core\\Actor\\UnstashAllBehavior',
        'Monadial\\Nexus\\Core\\Actor\\WithStashBehavior',
        'Monadial\\Nexus\\Core\\Actor\\WithStateBehavior',
        'Monadial\\Nexus\\Core\\Actor\\WithTimersBehavior',
    ];

    #[Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        if (!($issue instanceof DocblockTypeContradiction)
            && !($issue instanceof TypeDoesNotContainType)
            && !($issue instanceof RedundantConditionGivenDocblockType)
        ) {
            return null;
        }

        $message = $issue->message;

        if (!str_contains($message, 'Behavior<')) {
            return null;
        }

        foreach (self::BEHAVIOR_SUBCLASSES as $subclass) {
            if (str_contains($message, $subclass)) {
                return false;
            }
        }

        return null;
    }
}
