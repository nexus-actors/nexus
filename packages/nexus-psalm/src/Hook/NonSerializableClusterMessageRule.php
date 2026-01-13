<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Psalm\Issue\NonSerializableClusterMessage;
use Monadial\Nexus\Serialization\MessageType;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use function strcasecmp;
use function strtolower;

final class NonSerializableClusterMessageRule implements AfterMethodCallAnalysisInterface
{
    private const array CHECKED_METHODS = [
        'monadial\nexus\cluster\remoteactorref::tell' => 0,
        'monadial\nexus\core\actor\actorref::tell' => 0,
    ];

    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaringId = strtolower($event->getDeclaringMethodId());
        $argIndex = self::CHECKED_METHODS[$declaringId] ?? null;

        if ($argIndex === null) {
            return;
        }

        if (!self::callerIsRemoteRef($event)) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if (!isset($args[$argIndex])) {
            return;
        }

        $messageArg = $args[$argIndex];
        $argType = $event->getStatementsSource()->getNodeTypeProvider()->getType($messageArg->value);

        if ($argType === null) {
            return;
        }

        $codebase = $event->getCodebase();

        foreach ($argType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (self::hasMessageTypeAttribute($codebase, $atomic->value)) {
                continue;
            }

            IssueBuffer::accepts(
                new NonSerializableClusterMessage(
                    $atomic->value,
                    new CodeLocation($event->getStatementsSource(), $messageArg->value),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function callerIsRemoteRef(AfterMethodCallAnalysisEvent $event): bool
    {
        $callerType = $event->getStatementsSource()->getNodeTypeProvider()->getType(
            $event->getExpr()->var,
        );

        if ($callerType === null) {
            return false;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && strcasecmp($atomic->value, RemoteActorRef::class) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function hasMessageTypeAttribute(Codebase $codebase, string $className): bool
    {
        if (!$codebase->classlike_storage_provider->has($className)) {
            return true;
        }

        $storage = $codebase->classlike_storage_provider->get($className);

        foreach ($storage->attributes as $attribute) {
            if (strcasecmp($attribute->fq_class_name, MessageType::class) === 0) {
                return true;
            }
        }

        return false;
    }
}
