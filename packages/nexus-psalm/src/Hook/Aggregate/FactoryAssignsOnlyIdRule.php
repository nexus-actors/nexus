<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Aggregate;

use Monadial\Nexus\Psalm\Issue\FactoryAssignsOnlyId;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

use function is_array;

/**
 * @internal
 *
 * Enforces v6 §9.1.1: an EVENT-SOURCED aggregate's static factory body
 * must only call `new self($id)` and `recordThat(new SomeEvent(...))`.
 * Direct property assignment (`$this->customer = …`,
 * `$order->customer = …`) is forbidden — state must flow through
 * `apply()` after `recordThat()` so the recorded stream stays in lock-
 * step with the in-memory state.
 *
 * Stateful aggregates (`StatefulAggregateRoot`) are intentionally
 * exempt: their documented pattern includes direct property mutation
 * inside command methods and factories.
 *
 * Detection: any concrete class extending `EventSourcedAggregateRoot`,
 * any `public static` method on it, any `Assign` whose target is a
 * `PropertyFetch` rooted at `$this` or any plain variable.
 */
final class FactoryAssignsOnlyIdRule implements AfterClassLikeAnalysisInterface
{
    private const string EVENT_SOURCED_AGGREGATE = 'monadial\nexus\ddd\core\aggregate\eventsourcedaggregateroot';

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint */
    #[Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event)
    {
        $storage = $event->getClasslikeStorage();

        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->parent_classes[self::EVENT_SOURCED_AGGREGATE])) {
            return null;
        }

        $stmt = $event->getStmt();
        $source = $event->getStatementsSource();
        $suppressed = $source->getSuppressedIssues();

        foreach ($stmt->stmts as $methodNode) {
            if (!$methodNode instanceof ClassMethod) {
                continue;
            }

            if (!self::isFactoryCandidate($methodNode)) {
                continue;
            }

            if ($methodNode->stmts === null) {
                continue;
            }

            $methodName = $methodNode->name->toString();

            self::scanForPropertyAssignments($methodNode->stmts, $storage->name, $methodName, $event, $suppressed);
        }

        return null;
    }

    private static function isFactoryCandidate(ClassMethod $method): bool
    {
        if (!$method->isStatic() || !$method->isPublic()) {
            return false;
        }

        if ($method->isAbstract()) {
            return false;
        }

        return $method->name->toLowerString() !== '__construct';
    }

    /**
     * @param array<array-key, Node\Stmt> $stmts
     * @param array<array-key, string> $suppressed
     */
    private static function scanForPropertyAssignments(
        array $stmts,
        string $className,
        string $methodName,
        AfterClassLikeAnalysisEvent $event,
        array $suppressed,
    ): void {
        foreach ($stmts as $node) {
            self::walk($node, $className, $methodName, $event, $suppressed);
        }
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function walk(
        Node $node,
        string $className,
        string $methodName,
        AfterClassLikeAnalysisEvent $event,
        array $suppressed,
    ): void {
        if ($node instanceof Assign) {
            $target = $node->var;

            if ($target instanceof PropertyFetch) {
                $propertyName = self::resolvePropertyName($target);

                if ($propertyName !== null && self::targetsAggregateScope($target)) {
                    IssueBuffer::accepts(
                        new FactoryAssignsOnlyId(
                            $className,
                            $methodName,
                            $propertyName,
                            new CodeLocation($event->getStatementsSource(), $node),
                        ),
                        $suppressed,
                    );
                }
            }
        }

        foreach ($node->getSubNodeNames() as $subName) {
            /** @var mixed $sub */
            $sub = $node->{$subName};

            if (is_array($sub)) {
                /** @var mixed $child */
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        self::walk($child, $className, $methodName, $event, $suppressed);
                    }
                }
            } elseif ($sub instanceof Node) {
                self::walk($sub, $className, $methodName, $event, $suppressed);
            }
        }
    }

    private static function resolvePropertyName(PropertyFetch $fetch): ?string
    {
        if ($fetch->name instanceof Identifier) {
            return $fetch->name->toString();
        }

        return null;
    }

    /**
     * Property writes via `$this->foo = …` always count. Writes via
     * `$x->foo = …` count when `$x` is a plain local variable — the
     * factory pattern uses an aggregate-typed local (`$order`) and we
     * cannot resolve its declared type here without the symbol table.
     * Treating any local-variable property write as a factory write is
     * the documented heuristic — false positives on unrelated locals are
     * vanishingly rare inside a public static factory.
     */
    private static function targetsAggregateScope(PropertyFetch $fetch): bool
    {
        return $fetch->var instanceof Variable;
    }
}
