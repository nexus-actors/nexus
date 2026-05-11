<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\CommandReturnValueAssigned;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;

use function is_array;
use function strtolower;

/**
 * @internal
 *
 * Enforces panel H11: `$x = $bus->dispatchCommand($cmd)` is dead code —
 * `dispatchCommand()` returns void. Adopters who need an outcome should
 * call `tryDispatch()` which returns `Either<Throwable, Accepted>`.
 *
 * Detection: any `Assign` whose right-hand side is a `MethodCall` to
 * `dispatchCommand` on a variable whose static type implements
 * `Monadial\Nexus\Ddd\Messaging\Bus\CommandBus`.
 */
final class CommandReturnValueIgnoredRule implements AfterFunctionLikeAnalysisInterface
{
    private const string COMMAND_BUS = 'monadial\nexus\ddd\messaging\bus\commandbus';
    private const string METHOD_NAME = 'dispatchcommand';

    #[Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $stmts = $event->getStmt()->getStmts();

        if ($stmts === null) {
            return null;
        }

        $source = $event->getStatementsSource();
        $suppressed = $source->getSuppressedIssues();
        $nodeTypes = $event->getNodeTypeProvider();

        foreach ($stmts as $node) {
            self::walk($node, $source, $nodeTypes, $suppressed);
        }

        return null;
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function walk(
        Node $node,
        StatementsSource $source,
        NodeTypeProvider $nodeTypes,
        array $suppressed,
    ): void {
        if ($node instanceof Assign && $node->expr instanceof MethodCall) {
            self::inspectMethodCall($node->expr, $node, $source, $nodeTypes, $suppressed);
        }

        foreach ($node->getSubNodeNames() as $subName) {
            /** @var mixed $sub */
            $sub = $node->{$subName};

            if (is_array($sub)) {
                /** @var mixed $child */
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        self::walk($child, $source, $nodeTypes, $suppressed);
                    }
                }
            } elseif ($sub instanceof Node) {
                self::walk($sub, $source, $nodeTypes, $suppressed);
            }
        }
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function inspectMethodCall(
        MethodCall $call,
        Assign $assign,
        StatementsSource $source,
        NodeTypeProvider $nodeTypes,
        array $suppressed,
    ): void {
        if (!$call->name instanceof Identifier) {
            return;
        }

        if (strtolower($call->name->toString()) !== self::METHOD_NAME) {
            return;
        }

        $callerType = $nodeTypes->getType($call->var);

        if ($callerType === null) {
            return;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject && !$atomic instanceof TGenericObject) {
                continue;
            }

            if (!self::implementsCommandBus($source, $atomic->value)) {
                continue;
            }

            IssueBuffer::accepts(
                new CommandReturnValueAssigned(new CodeLocation($source, $assign)),
                $suppressed,
            );

            return;
        }
    }

    private static function implementsCommandBus(StatementsSource $source, string $className): bool
    {
        $key = strtolower($className);

        if ($key === self::COMMAND_BUS) {
            return true;
        }

        $codebase = $source->getCodebase();

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        return isset($storage->class_implements[self::COMMAND_BUS])
            || isset($storage->parent_interfaces[self::COMMAND_BUS]);
    }
}
