<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Messaging;

use Monadial\Nexus\Psalm\Issue\InvalidEventListenerSignature;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class EventListenerSignatureRule implements AfterClassLikeAnalysisInterface
{
    private const string MARKER = 'monadial\nexus\ddd\messaging\handler\eventlistener';
    private const string DOMAIN_EVENT = 'monadial\nexus\ddd\core\entity\domainevent';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->class_implements[self::MARKER])) {
            return null;
        }

        $reason = HandlerSignatureInspector::validateInvoke(
            $event->getCodebase(),
            $storage,
            self::DOMAIN_EVENT,
            true,
        );

        if ($reason !== null) {
            $location = $storage->location ?? new CodeLocation($event->getStatementsSource(), $event->getStmt());
            IssueBuffer::accepts(
                new InvalidEventListenerSignature($storage->name, $reason, $location),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }
}
