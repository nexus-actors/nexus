<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\NonReadonlyMessage;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;

final class ReadonlyMessageRule implements AfterMethodCallAnalysisInterface
{
    private const array CHECKED_METHODS = [
        'monadial\nexus\core\actor\actorcontext::scheduleonce' => 1,
        'monadial\nexus\core\actor\actorcontext::schedulerepeatedly' => 2,
        'monadial\nexus\core\actor\actorref::tell' => 0,
    ];

    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $messageArgIndex = self::messageArgIndex($event->getDeclaringMethodId());

        if ($messageArgIndex === null) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if (!isset($args[$messageArgIndex])) {
            return;
        }

        $messageArg = $args[$messageArgIndex];
        $argType = $event->getStatementsSource()->getNodeTypeProvider()->getType($messageArg->value);

        if ($argType === null) {
            return;
        }

        $codebase = $event->getCodebase();

        foreach ($argType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (!$codebase->classlike_storage_provider->has($atomic->value)) {
                continue;
            }

            $storage = $codebase->classlike_storage_provider->get($atomic->value);

            if ($storage->is_interface || $storage->is_enum) {
                continue;
            }

            if ($storage->readonly) {
                continue;
            }

            IssueBuffer::accepts(
                new NonReadonlyMessage(
                    $atomic->value,
                    new CodeLocation($event->getStatementsSource(), $messageArg->value),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function messageArgIndex(string $declaringMethodId): ?int
    {
        return self::CHECKED_METHODS[strtolower($declaringMethodId)] ?? null;
    }
}
