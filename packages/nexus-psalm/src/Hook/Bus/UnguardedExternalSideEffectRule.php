<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook\Bus;

use Monadial\Nexus\Psalm\Issue\UnguardedExternalSideEffect;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;

use function is_array;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * @internal
 *
 * Warns (per panel H11) when a command-handler method calls an external
 * side-effect API (Symfony Mailer, Guzzle, Stripe SDK …) while the
 * command class lacks `#[IdempotencyKey]`. Redelivery would re-trigger
 * the external side effect — adding `#[IdempotencyKey]` makes the
 * handler idempotent.
 *
 * P0 scope: hard-coded allow-list of common external SDKs. Adopters
 * who need to widen the list will configure via plugin XML in a later
 * phase. This rule's purpose is the warning, not perfect coverage.
 */
final class UnguardedExternalSideEffectRule implements AfterFunctionLikeAnalysisInterface
{
    private const string COMMAND_HANDLER = 'monadial\nexus\ddd\messaging\handler\commandhandler';
    private const string HANDLER_ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\handler';
    private const string IDEMPOTENCY_KEY_ATTRIBUTE = 'monadial\nexus\ddd\bus\attribute\idempotencykey';

    /**
     * Lowercase FQCN patterns. A trailing `\*` means "any class under this
     * namespace prefix"; otherwise the pattern is an exact match.
     *
     */
    private const array EXTERNAL_CLASS_PATTERNS = [
        'aws\*',
        'guzzlehttp\client',
        'guzzlehttp\clientinterface',
        'stripe\*',
        'symfony\component\httpclient\*',
        'symfony\component\mailer\*',
        'symfony\component\notifier\*',
    ];

    #[Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof ClassMethod) {
            return null;
        }

        $source = $event->getStatementsSource();
        $handlerClass = $source->getFQCLN();

        if ($handlerClass === null) {
            return null;
        }

        $methodStorage = $event->getFunctionlikeStorage();

        if (!self::isCommandHandlerMethod($event->getCodebase(), $handlerClass, $methodStorage, $stmt)) {
            return null;
        }

        $commandClass = self::firstParamObjectFqcn($methodStorage);

        if ($commandClass === null) {
            return null;
        }

        if (self::commandHasIdempotencyKey($event->getCodebase(), $commandClass)) {
            return null;
        }

        $methodName = $stmt->name->toString();

        self::walkForExternalCalls(
            $stmt,
            $event->getNodeTypeProvider(),
            $source,
            $handlerClass,
            $methodName,
            $commandClass,
            $source->getSuppressedIssues(),
        );

        return null;
    }

    private static function isCommandHandlerMethod(
        Codebase $codebase,
        string $handlerClass,
        FunctionLikeStorage $method,
        ClassMethod $node,
    ): bool {
        if (self::methodHasHandlerAttribute($method)) {
            return true;
        }

        if ($node->name->toLowerString() !== '__invoke') {
            return false;
        }

        $key = strtolower($handlerClass);

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        return isset($storage->class_implements[self::COMMAND_HANDLER]);
    }

    private static function methodHasHandlerAttribute(FunctionLikeStorage $method): bool
    {
        foreach ($method->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::HANDLER_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }

    private static function firstParamObjectFqcn(FunctionLikeStorage $method): ?string
    {
        if ($method->params === []) {
            return null;
        }

        $type = $method->params[0]->signature_type ?? $method->params[0]->type;

        if ($type === null) {
            return null;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject || $atomic instanceof TNamedObject) {
                return $atomic->value;
            }
        }

        return null;
    }

    private static function commandHasIdempotencyKey(Codebase $codebase, string $commandClass): bool
    {
        $key = strtolower($commandClass);

        if (!$codebase->classlike_storage_provider->has($key)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($key);

        foreach ($storage->attributes as $attribute) {
            if (strtolower($attribute->fq_class_name) === self::IDEMPOTENCY_KEY_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function walkForExternalCalls(
        FunctionLike $node,
        NodeTypeProvider $nodeTypes,
        StatementsSource $source,
        string $handlerClass,
        string $methodName,
        string $commandClass,
        array $suppressed,
    ): void {
        $stmts = $node->getStmts();

        if ($stmts === null) {
            return;
        }

        foreach ($stmts as $stmt) {
            self::walk($stmt, $nodeTypes, $source, $handlerClass, $methodName, $commandClass, $suppressed);
        }
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function walk(
        Node $node,
        NodeTypeProvider $nodeTypes,
        StatementsSource $source,
        string $handlerClass,
        string $methodName,
        string $commandClass,
        array $suppressed,
    ): void {
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            self::inspectCall($node, $nodeTypes, $source, $handlerClass, $methodName, $commandClass, $suppressed);
        }

        foreach ($node->getSubNodeNames() as $subName) {
            /** @var mixed $sub */
            $sub = $node->{$subName};

            if (is_array($sub)) {
                /** @var mixed $child */
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        self::walk($child, $nodeTypes, $source, $handlerClass, $methodName, $commandClass, $suppressed);
                    }
                }
            } elseif ($sub instanceof Node) {
                self::walk($sub, $nodeTypes, $source, $handlerClass, $methodName, $commandClass, $suppressed);
            }
        }
    }

    /**
     * @param array<array-key, string> $suppressed
     */
    private static function inspectCall(
        MethodCall $call,
        NodeTypeProvider $nodeTypes,
        StatementsSource $source,
        string $handlerClass,
        string $methodName,
        string $commandClass,
        array $suppressed,
    ): void {
        $callerType = $nodeTypes->getType($call->var);

        if ($callerType === null) {
            return;
        }

        if (!$call->name instanceof Identifier) {
            return;
        }

        $calledMethod = $call->name->toString();

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject && !$atomic instanceof TGenericObject) {
                continue;
            }

            if (!self::matchesExternalPattern($atomic->value)) {
                continue;
            }

            IssueBuffer::accepts(
                new UnguardedExternalSideEffect(
                    $handlerClass,
                    $methodName,
                    $atomic->value . '::' . $calledMethod,
                    $commandClass,
                    new CodeLocation($source, $call),
                ),
                $suppressed,
            );

            return;
        }
    }

    private static function matchesExternalPattern(string $className): bool
    {
        $lower = strtolower($className);

        foreach (self::EXTERNAL_CLASS_PATTERNS as $pattern) {
            if (str_ends_with($pattern, '\\*')) {
                $prefix = substr($pattern, 0, -1);

                if (str_starts_with($lower, $prefix)) {
                    return true;
                }

                continue;
            }

            if ($lower === $pattern) {
                return true;
            }
        }

        return false;
    }
}
