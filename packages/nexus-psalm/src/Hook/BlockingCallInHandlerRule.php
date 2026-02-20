<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\BlockingCallInHandler;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterEveryFunctionCallAnalysisEvent;

use function in_array;
use function strtolower;

final class BlockingCallInHandlerRule implements AfterEveryFunctionCallAnalysisInterface
{
    private const array ACTOR_INTERFACES = [
        'monadial\nexus\core\actor\actorhandler',
        'monadial\nexus\core\actor\statefulactorhandler',
    ];

    private const array BLOCKING_FUNCTIONS = [
        'sleep',
        'usleep',
        'time_nanosleep',
        'time_sleep_until',
        'file_get_contents',
        'file_put_contents',
        'fread',
        'fwrite',
        'fgets',
        'fopen',
        'curl_exec',
        'proc_open',
        'shell_exec',
        'exec',
        'system',
        'passthru',
        'popen',
    ];

    #[Override]
    public static function afterEveryFunctionCallAnalysis(AfterEveryFunctionCallAnalysisEvent $event): void
    {
        $functionId = strtolower($event->getFunctionId());

        if (!in_array($functionId, self::BLOCKING_FUNCTIONS, true)) {
            return;
        }

        $className = $event->getStatementsSource()->getFQCLN();

        if ($className === null) {
            return;
        }

        if (!self::implementsActorHandler($event, $className)) {
            return;
        }

        IssueBuffer::accepts(
            new BlockingCallInHandler(
                $functionId,
                new CodeLocation($event->getStatementsSource(), $event->getExpr()),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );
    }

    private static function implementsActorHandler(AfterEveryFunctionCallAnalysisEvent $event, string $className): bool
    {
        $codebase = $event->getCodebase();

        if (!$codebase->classlike_storage_provider->has($className)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($className);

        foreach (self::ACTOR_INTERFACES as $interface) {
            if (isset($storage->class_implements[$interface])) {
                return true;
            }
        }

        return false;
    }
}
