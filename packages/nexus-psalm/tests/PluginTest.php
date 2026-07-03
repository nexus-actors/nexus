<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function explode;
use function implode;
use function str_contains;

final class PluginTest extends TestCase
{
    private const string PSALM_BIN = 'vendor/bin/psalm';

    #[Test]
    public function readonlyMessageRuleDetectsNonReadonlyMessage(): void
    {
        $output = $this->runPsalmOnFixture('ReadonlyMessageFixture.php');

        self::assertStringContains('NonReadonlyMessage', $output, 'Expected NonReadonlyMessage issue for BadMessage');
        self::assertStringContains('BadMessage', $output, 'Expected BadMessage class name in output');
    }

    #[Test]
    public function readonlyMessageRuleAllowsReadonlyMessage(): void
    {
        $output = $this->runPsalmOnFixture('ReadonlyMessageFixture.php');
        $lines = $this->filterIssueLines($output, 'NonReadonlyMessage');

        // 3 issues: tell() + scheduleOnce() + scheduleRepeatedly() — all for BadMessage, never GoodMessage
        self::assertCount(3, $lines, 'Expected exactly 3 NonReadonlyMessage issues');
        self::assertStringNotContains('GoodMessage', $output);
    }

    #[Test]
    public function readonlyMessageRuleDetectsScheduledMutableMessage(): void
    {
        $output = $this->runPsalmOnFixture('ReadonlyMessageFixture.php');
        $lines = $this->filterIssueLines($output, 'NonReadonlyMessage');

        // 3 issues: tell (line 26), scheduleOnce (line 38), scheduleRepeatedly (line 39)
        self::assertCount(3, $lines, 'Expected 3 NonReadonlyMessage issues');
        self::assertStringContains(':26:', $lines[0], 'First issue should be tell() on line 26');
        self::assertStringContains(':38:', $lines[1], 'Second issue should be scheduleOnce on line 38');
        self::assertStringContains(':39:', $lines[2], 'Third issue should be scheduleRepeatedly on line 39');
    }

    #[Test]
    public function remoteMessageRuleDetectsUnregisteredMessage(): void
    {
        $output = $this->runPsalmOnFixture('ClusterMessageFixture.php');
        $lines = $this->filterIssueLines($output, 'NonSerializableRemoteMessage');

        self::assertCount(1, $lines, 'Expected exactly 1 NonSerializableRemoteMessage issue');
        self::assertStringContains('UnregisteredMessage', $lines[0]);
    }

    #[Test]
    public function remoteMessageRuleAllowsRegisteredMessage(): void
    {
        $output = $this->runPsalmOnFixture('ClusterMessageFixture.php');

        self::assertStringNotContains('RegisteredMessage', $output);
    }

    #[Test]
    public function blockingCallRuleDetectsBlockingInHandler(): void
    {
        $output = $this->runPsalmOnFixture('BlockingCallFixture.php');
        $lines = $this->filterIssueLines($output, 'BlockingCallInHandler');

        self::assertCount(1, $lines, 'Expected exactly 1 BlockingCallInHandler issue');
        self::assertStringContains('sleep', $lines[0]);
    }

    #[Test]
    public function blockingCallRuleIgnoresNonActorClasses(): void
    {
        $output = $this->runPsalmOnFixture('BlockingCallFixture.php');

        self::assertStringNotContains('RegularClassWithSleep', $output);
    }

    #[Test]
    public function closureCaptureRuleDetectsRefCapture(): void
    {
        $output = $this->runPsalmOnFixture('ClosureCaptureFixture.php');
        $lines = $this->filterIssueLines($output, 'MutableClosureCapture');

        self::assertCount(1, $lines, 'Expected exactly 1 MutableClosureCapture issue');
        self::assertStringContains('counter', $lines[0]);
    }

    #[Test]
    public function closureCaptureRuleAllowsValueCapture(): void
    {
        $output = $this->runPsalmOnFixture('ClosureCaptureFixture.php');

        self::assertStringNotContains('factoryWithValueCapture', $output);
        self::assertStringNotContains('factoryWithArrowFunction', $output);
    }

    #[Test]
    public function mutableActorStateRuleDetectsPublicMutableProperty(): void
    {
        $output = $this->runPsalmOnFixture('MutableActorStateFixture.php');

        self::assertStringContains(
            'MutableActorState',
            $output,
            'Expected MutableActorState issue for BadActorHandler',
        );
        self::assertStringContains('BadActorHandler', $output);
    }

    #[Test]
    public function mutableActorStateRuleAllowsReadonlyHandler(): void
    {
        $output = $this->runPsalmOnFixture('MutableActorStateFixture.php');
        $lines = $this->filterIssueLines($output, 'MutableActorState');

        // Only one issue — for BadActorHandler
        self::assertCount(1, $lines, 'Expected exactly 1 MutableActorState issue');
        self::assertStringContains('BadActorHandler', $lines[0]);
    }

    #[Test]
    public function mutableActorStateRuleIgnoresNonActorClasses(): void
    {
        $output = $this->runPsalmOnFixture('MutableActorStateFixture.php');

        self::assertStringNotContains('RegularClassWithMutableProperty', $output);
    }

    #[Test]
    public function cloneWithReturnTypeMatchesFirstArgument(): void
    {
        $output = $this->runPsalmOnFixture('CloneWithFixture.php');
        $lines = $this->filterIssueLines($output, 'Trace');

        self::assertCount(1, $lines, 'Expected exactly 1 Trace issue from @psalm-trace');
        self::assertStringContains('CloneWithFixture', $lines[0], 'clone() should return CloneWithFixture, not object');
    }

    #[Test]
    public function cloneWithDoesNotProduceTypeErrors(): void
    {
        $output = $this->runPsalmOnFixture('CloneWithFixture.php');

        self::assertStringNotContains('LessSpecificReturnStatement', $output);
        self::assertStringNotContains('MoreSpecificReturnType', $output);
    }

    #[Test]
    public function askReturnTypeProviderRewritesTaggedMessageToTypedFuture(): void
    {
        $output = $this->runPsalmOnFixture('AskReplyTypeFixture.php');

        // taggedAskReturnsTypedFuture() declares `@return Future<FixtureOrder>`
        // and returns the result of $orders->ask(new GetOrderFixture(...), ...).
        // The hook must rewrite ask() to Future<FixtureOrder> so the return
        // matches. If the hook were broken, Psalm would report
        // MoreSpecificReturnType (the actual return is wider than declared).
        // The fixture compiles clean (only the deliberate mismatch fires).
        self::assertStringNotContains(':52:', $output, 'taggedAskReturnsTypedFuture line 52 must be clean');
        self::assertStringNotContains(':53:', $output, 'taggedAskReturnsTypedFuture line 53 must be clean');
        self::assertStringNotContains(':54:', $output, 'taggedAskReturnsTypedFuture line 54 must be clean');
    }

    #[Test]
    public function askReturnTypeProviderLetsTypedAwaitFlowThrough(): void
    {
        $output = $this->runPsalmOnFixture('AskReplyTypeFixture.php');

        // taggedAskAwaitReturnsTypedValue() (lines 60-65 in the fixture)
        // returns FixtureOrder from ->await(). Without the hook, ->await()
        // returns object/mixed and Psalm would report
        // InvalidReturnStatement on THAT method specifically. The
        // mismatched-return test method (lines 70-80) deliberately
        // produces InvalidReturnStatement; we filter it out by line.
        $issues = $this->filterIssueLines($output, 'InvalidReturnStatement');

        foreach ($issues as $line) {
            self::assertFalse(
                str_contains($line, ':65:') || str_contains($line, ':66:') || str_contains($line, ':67:'),
                "Unexpected InvalidReturnStatement on taggedAskAwaitReturnsTypedValue:\n{$line}",
            );
        }
    }

    #[Test]
    public function askReturnTypeProviderFlagsMismatchedDeclaredReturn(): void
    {
        $output = $this->runPsalmOnFixture('AskReplyTypeFixture.php');

        // taggedAskMismatchedReturnFails() declares Future<UntaggedRequest>
        // but the hook says the actual return is Future<FixtureOrder>. Psalm
        // MUST report this — proves the hook is actually constraining the
        // type, not just adding it as an upper bound.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        self::assertNotEmpty($lines, "Expected InvalidReturnStatement on the mismatched-return fixture:\n{$output}");

        $found = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'FixtureOrder') && str_contains($line, 'UntaggedRequest')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected an InvalidReturnStatement mentioning both FixtureOrder (actual) and UntaggedRequest (declared):\n"
            . implode("\n", $lines),
        );
    }

    #[Test]
    public function behaviorReceiveHookInfersTypedMessageGeneric(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorReceiveInferenceFixture.php');

        // typedClosureReturnsTypedReceive() declares `@return ReceiveBehavior<FixtureGreet>`
        // and returns Behavior::receive(...) with a typed closure parameter.
        // If the hook is broken, Psalm reports MoreSpecificReturnType / LessSpecificReturnStatement
        // because the actual return resolves to ReceiveBehavior<object>.
        $issues = $this->filterIssueLines($output, 'typedClosureReturnsTypedReceive');

        self::assertEmpty(
            $issues,
            "Expected typedClosureReturnsTypedReceive to be clean — hook should infer ReceiveBehavior<FixtureGreet>:\n"
            . implode("\n", $issues),
        );
    }

    #[Test]
    public function behaviorReceiveHookFallsThroughForObjectParam(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorReceiveInferenceFixture.php');

        // untypedClosureReturnsObjectReceive() takes `object $msg`. The
        // fixture binds the result to $b and emits @psalm-trace $b so the
        // RESOLVED type is observable in Psalm's output. Declared-return
        // matching alone is too loose — it would pass even if the hook
        // returned ReceiveBehavior<never>. The trace pins the literal type.
        $traces = $this->filterIssueLines($output, 'Trace');

        $found = false;

        foreach ($traces as $line) {
            if (str_contains($line, 'ReceiveBehavior<object>')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected @psalm-trace to report ReceiveBehavior<object> for the object-param fall-through case:\n"
            . implode("\n", $traces),
        );
    }

    #[Test]
    public function behaviorReceiveHookFlagsMismatchedReturn(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorReceiveInferenceFixture.php');

        // typedClosureWithMismatchedReturnFails() declares
        // ReceiveBehavior<FixtureUnrelated> but the closure takes FixtureGreet.
        // The hook MUST rewrite the call's return to ReceiveBehavior<FixtureGreet>
        // and Psalm MUST report InvalidReturnStatement mentioning both.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        $found = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'FixtureGreet') && str_contains($line, 'FixtureUnrelated')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected an InvalidReturnStatement mentioning both FixtureGreet (actual) and FixtureUnrelated (declared):\n"
            . implode("\n", $lines),
        );
    }

    #[Test]
    public function behaviorSetupHookInfersGenericFromTypedActorContext(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorSetupInferenceFixture.php');

        // typedContextReturnsTypedSetup() declares SetupBehavior<FixtureBootstrap>.
        // Hook must read ActorContext<FixtureBootstrap> from the closure's
        // first param and lift it out as the SetupBehavior generic.
        $issues = $this->filterIssueLines($output, 'typedContextReturnsTypedSetup');

        self::assertEmpty(
            $issues,
            "Expected typedContextReturnsTypedSetup to be clean — hook should infer SetupBehavior<FixtureBootstrap>:\n"
            . implode("\n", $issues),
        );
    }

    #[Test]
    public function behaviorSetupHookFallsThroughForBareActorContext(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorSetupInferenceFixture.php');

        // bareContextReturnsObjectSetup() uses ActorContext with no generic.
        // The fixture binds the result to $b and emits @psalm-trace $b so
        // the RESOLVED type is observable. Declared-return matching alone
        // would pass even if the hook returned SetupBehavior<never>; the
        // trace pins the literal type that Psalm's default produces.
        $traces = $this->filterIssueLines($output, 'Trace');

        $found = false;

        foreach ($traces as $line) {
            if (str_contains($line, 'SetupBehavior<object>')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected @psalm-trace to report SetupBehavior<object> for the bare-ActorContext fall-through case:\n"
            . implode("\n", $traces),
        );
    }

    #[Test]
    public function behaviorSetupHookFlagsMismatchedReturn(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorSetupInferenceFixture.php');

        // typedContextWithMismatchedReturnFails() declares
        // SetupBehavior<FixtureUnrelatedSetup> but the closure types
        // ActorContext<FixtureBootstrap>. The hook MUST rewrite the return
        // to SetupBehavior<FixtureBootstrap> and Psalm MUST flag mismatch.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        $found = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'FixtureBootstrap') && str_contains($line, 'FixtureUnrelatedSetup')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected an InvalidReturnStatement mentioning both FixtureBootstrap (actual) and FixtureUnrelatedSetup (declared):\n"
            . implode("\n", $lines),
        );
    }

    #[Test]
    public function behaviorWithStateHookInfersBothGenericsFromTypedClosure(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorWithStateInferenceFixture.php');

        // fullyTypedClosureReturnsBothGenerics() declares
        // WithStateBehavior<FixtureIncrement, int>. Hook must rewrite the
        // return so it matches; broken hook → MoreSpecificReturnType.
        $issues = $this->filterIssueLines($output, 'fullyTypedClosureReturnsBothGenerics');

        self::assertEmpty(
            $issues,
            "Expected fullyTypedClosureReturnsBothGenerics to be clean — hook should infer both generics:\n"
            . implode("\n", $issues),
        );
    }

    #[Test]
    public function behaviorWithStateHookPartiallyInfersWhenMessageIsObject(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorWithStateInferenceFixture.php');

        // objectMessageWithTypedStateReturnsPartialGeneric() takes `object $msg`
        // but typed `int $count`. The hook should still narrow state, so the
        // declared WithStateBehavior<object, int> must match cleanly.
        $issues = $this->filterIssueLines($output, 'objectMessageWithTypedStateReturnsPartialGeneric');

        self::assertEmpty(
            $issues,
            "Expected objectMessageWithTypedStateReturnsPartialGeneric to be clean — hook must still narrow state:\n"
            . implode("\n", $issues),
        );
    }

    #[Test]
    public function behaviorWithStateHookFlagsMismatchedStateGeneric(): void
    {
        $output = $this->runPsalmOnFixture('BehaviorWithStateInferenceFixture.php');

        // typedClosureWithMismatchedStateReturnFails() declares
        // WithStateBehavior<FixtureIncrement, string> but the closure says
        // S = int. Psalm MUST report InvalidReturnStatement — proves the
        // hook is constraining the state generic, not just adding it.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        $found = false;

        foreach ($lines as $line) {
            if (
                str_contains($line, 'FixtureIncrement')
                && str_contains($line, 'string')
                && str_contains($line, 'int')
            ) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected InvalidReturnStatement mentioning FixtureIncrement, string (declared), int (actual):\n"
            . implode("\n", $lines),
        );
    }

    #[Test]
    public function mismatchedReplyTypeFlagsWrongReply(): void
    {
        $output = $this->runPsalmOnFixture('MismatchedReplyTypeFixture.php');
        $lines = $this->filterIssueLines($output, 'MismatchedReplyType');

        // 2 expected mismatches: line 61 (wrongReply) + line 87 (matchPattern second arm)
        self::assertCount(2, $lines, "Expected 2 MismatchedReplyType issues:\n" . implode("\n", $lines));
    }

    #[Test]
    public function mismatchedReplyTypeFiresOnDirectlyTypedHandler(): void
    {
        $output = $this->runPsalmOnFixture('MismatchedReplyTypeFixture.php');
        $lines = $this->filterIssueLines($output, 'MismatchedReplyType');

        // wrongReply() at line 61: message=FetchOrderMessage, expected=ReplyOrder, got=ReplyUser
        $found = false;

        foreach ($lines as $line) {
            if (
                str_contains($line, ':61:')
                && str_contains($line, 'FetchOrderMessage')
                && str_contains($line, 'ReplyOrder')
                && str_contains($line, 'ReplyUser')
            ) {
                $found = true;

                break;
            }
        }

        self::assertTrue($found, "Expected MismatchedReplyType on wrongReply line 61:\n" . implode("\n", $lines));
    }

    #[Test]
    public function mismatchedReplyTypeRespectsMatchPatternNarrowing(): void
    {
        $output = $this->runPsalmOnFixture('MismatchedReplyTypeFixture.php');
        $lines = $this->filterIssueLines($output, 'MismatchedReplyType');

        // matchPattern() second arm at line 87: message=FetchUserMessage, expected=ReplyUser, got=ReplyOrder
        $found = false;

        foreach ($lines as $line) {
            if (
                str_contains($line, ':87:')
                && str_contains($line, 'FetchUserMessage')
                && str_contains($line, 'ReplyUser')
                && str_contains($line, 'ReplyOrder')
            ) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected MismatchedReplyType on matchPattern arm at line 87:\n" . implode("\n", $lines),
        );
    }

    #[Test]
    public function mismatchedReplyTypeAllowsCorrectReplies(): void
    {
        $output = $this->runPsalmOnFixture('MismatchedReplyTypeFixture.php');
        $lines = $this->filterIssueLines($output, 'MismatchedReplyType');

        // No issue on correctReply (line 51), untaggedAllowsAnything (line 73),
        // matchPattern first arm (line 83).
        foreach ($lines as $line) {
            self::assertFalse(
                str_contains($line, ':51:') || str_contains($line, ':73:') || str_contains($line, ':83:'),
                "Unexpected MismatchedReplyType on a legitimate reply:\n{$line}",
            );
        }
    }

    #[Test]
    public function pooledConnectionRuleDetectsDbalConnectionInActorHandler(): void
    {
        $output = $this->runPsalmOnFixture('PooledConnectionFixture.php');

        self::assertStringContains(
            'PooledConnectionInActorProperty',
            $output,
            'Expected PooledConnectionInActorProperty issue',
        );
        self::assertStringContains('ActorWithDbalConnection', $output);
    }

    #[Test]
    public function pooledConnectionRuleDetectsEntityManagerInStatefulActorHandler(): void
    {
        $output = $this->runPsalmOnFixture('PooledConnectionFixture.php');
        $lines = $this->filterIssueLines($output, 'PooledConnectionInActorProperty');

        self::assertCount(2, $lines, 'Expected exactly 2 PooledConnectionInActorProperty issues');
        self::assertStringContains('StatefulActorWithEntityManager', $output);
    }

    #[Test]
    public function pooledConnectionRuleIgnoresRegularServices(): void
    {
        $output = $this->runPsalmOnFixture('PooledConnectionFixture.php');

        self::assertStringNotContains('RegularServiceWithConnection', $output);
    }

    #[Test]
    public function missingTransactionalDeclarationRuleDetectsMissingConnectionParam(): void
    {
        $output = $this->runPsalmOnFixture('MissingTransactionalFixture.php');

        self::assertStringContains(
            'MissingTransactionalDeclaration',
            $output,
            'Expected MissingTransactionalDeclaration issue for BadTransactionalHandler',
        );
        self::assertStringContains('BadTransactionalHandler', $output);
    }

    #[Test]
    public function missingTransactionalDeclarationRuleAllowsConnectionParam(): void
    {
        $output = $this->runPsalmOnFixture('MissingTransactionalFixture.php');
        $lines = $this->filterIssueLines($output, 'MissingTransactionalDeclaration');

        self::assertCount(1, $lines, 'Expected exactly 1 MissingTransactionalDeclaration issue');
        self::assertStringNotContains('GoodTransactionalWithConnection', $output);
    }

    #[Test]
    public function missingTransactionalDeclarationRuleAllowsEntityManagerParam(): void
    {
        $output = $this->runPsalmOnFixture('MissingTransactionalFixture.php');

        self::assertStringNotContains('GoodTransactionalWithEntityManager', $output);
    }

    #[Test]
    public function missingTransactionalDeclarationRuleIgnoresClassesWithoutAttribute(): void
    {
        $output = $this->runPsalmOnFixture('MissingTransactionalFixture.php');

        self::assertStringNotContains('PlainHandlerNoAttribute', $output);
    }

    #[Test]
    public function untypedActorRefRuleFlagsBareAndObjectParams(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        // 6 issues: UarBareParamService::setSink, UarObjectParamService::route,
        // UarSubtypeBypassService::connect, UarClosureHost closure param,
        // UarContainerService::setBare, UarContainerService::setErased
        self::assertCount(6, $lines, "Expected 6 UntypedActorRefInjection issues:\n" . implode("\n", $lines));
        self::assertStringContains('setSink', $output);
        self::assertStringContains('UarObjectParamService', $output);
        self::assertStringContains('UarSubtypeBypassService', $output);
        self::assertStringContains('setBare', $output);
        self::assertStringContains('setErased', $output);
        self::assertStringNotContains('setTyped', $output);
    }

    #[Test]
    public function untypedActorRefRuleAllowsTypedTemplatedSuppressedAndUnrelated(): void
    {
        $output = $this->runPsalmOnFixture('UntypedActorRefFixture.php');
        $lines = $this->filterIssueLines($output, 'UntypedActorRefInjection');

        foreach ($lines as $line) {
            foreach (['UarTypedParamService', 'UarTemplatedService', 'UarNullableTypedService', 'UarSuppressedService', 'UarUnrelatedService'] as $clean) {
                self::assertStringNotContains($clean, $line);
            }
        }
    }

    private function runPsalmOnFixture(string $fixture): string
    {
        $fixturePath = __DIR__ . '/Fixture/' . $fixture;
        $projectRoot = escapeshellarg(__DIR__ . '/../../..');
        $fixturePath = escapeshellarg($fixturePath);

        $command = "cd {$projectRoot} && php " . self::PSALM_BIN
            . ' --no-progress --no-cache --output-format=text'
            . ' ' . $fixturePath
            . ' 2>&1';

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }

    /** @return list<string> */
    private function filterIssueLines(string $output, string $issueType): array
    {
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, $issueType)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            $message !== '' ? $message : "Expected '{$needle}' in output:\n{$haystack}",
        );
    }

    private static function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertFalse(
            str_contains($haystack, $needle),
            $message !== '' ? $message : "Did not expect '{$needle}' in output:\n{$haystack}",
        );
    }
}
