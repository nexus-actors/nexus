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
    private const string TELL_METHOD = 'Monadial\Nexus\Core\Actor\ActorRef::tell';

    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        if (!self::isTellCall($event->getDeclaringMethodId())) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if ($args === []) {
            return;
        }

        $argType = $event->getStatementsSource()->getNodeTypeProvider()->getType($args[0]->value);

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
                    new CodeLocation($event->getStatementsSource(), $args[0]->value),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function isTellCall(string $declaringMethodId): bool
    {
        return strcasecmp($declaringMethodId, self::TELL_METHOD) === 0;
    }
}
