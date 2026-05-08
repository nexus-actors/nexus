<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Messaging;

use Monadial\Nexus\Psalm\Issue\DuplicateCommandHandler;
use Override;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

use function count;
use function strtolower;

/**
 * Project-wide rule: each Command class may have at most one CommandHandler
 * implementation. Tracks (commandClass → handlerClass) tuples as classes are
 * analyzed; emits an issue against the second-and-later handlers seen for
 * the same command.
 *
 * Order of analysis affects which class is reported as the duplicate, but
 * the error fires deterministically: if N classes handle the same command,
 * Psalm reports N-1 duplicates.
 */
final class OneCommandHandlerRule implements AfterClassLikeAnalysisInterface
{
    private const string MARKER = 'monadial\nexus\ddd\messaging\handler\commandhandler';

    /** @var array<lowercase-string, string> commandClassLower => first-seen handler FQCN */
    private static array $handlersByCommand = [];

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

        $method = $storage->methods['__invoke'] ?? null;

        if ($method === null || count($method->params) === 0) {
            return null;
        }

        $commandFqcn = HandlerSignatureInspector::firstNamedObjectFqcn($method->params[0]->signature_type);

        if ($commandFqcn === null) {
            return null;
        }

        $key = strtolower($commandFqcn);
        $existing = self::$handlersByCommand[$key] ?? null;

        if ($existing !== null && $existing !== $storage->name) {
            $location = $storage->location ?? new CodeLocation($event->getStatementsSource(), $event->getStmt());
            IssueBuffer::accepts(
                new DuplicateCommandHandler($commandFqcn, $storage->name, $existing, $location),
                $event->getStatementsSource()->getSuppressedIssues(),
            );

            return null;
        }

        self::$handlersByCommand[$key] = $storage->name;

        return null;
    }
}
