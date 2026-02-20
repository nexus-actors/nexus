<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\MutableClosureCapture;
use Override;
use PhpParser\Node\Expr\Closure;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

use function in_array;
use function is_string;
use function strtolower;

final class MutableClosureCaptureRule implements AfterMethodCallAnalysisInterface
{
    private const array FACTORY_METHODS = [
        'monadial\nexus\core\actor\props::fromfactory',
        'monadial\nexus\core\actor\props::fromstatefulfactory',
        'monadial\nexus\core\actor\props::fromcontainer',
    ];

    #[Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaringId = strtolower($event->getDeclaringMethodId());

        if (!in_array($declaringId, self::FACTORY_METHODS, true)) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if ($args === []) {
            return;
        }

        // The factory callable is the first arg for fromFactory/fromStatefulFactory,
        // and irrelevant for fromContainer (no closure arg). Check all args for safety.
        foreach ($args as $arg) {
            if (!$arg->value instanceof Closure) {
                continue;
            }

            foreach ($arg->value->uses as $use) {
                if ($use->byRef) {
                    $varName = $use->var->name;

                    if (!is_string($varName)) {
                        continue;
                    }

                    IssueBuffer::accepts(
                        new MutableClosureCapture(
                            $varName,
                            new CodeLocation($event->getStatementsSource(), $use),
                        ),
                        $event->getStatementsSource()->getSuppressedIssues(),
                    );
                }
            }
        }
    }
}
