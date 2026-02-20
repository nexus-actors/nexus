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
    public function clusterMessageRuleDetectsUnregisteredMessage(): void
    {
        $output = $this->runPsalmOnFixture('ClusterMessageFixture.php');
        $lines = $this->filterIssueLines($output, 'NonSerializableClusterMessage');

        self::assertCount(1, $lines, 'Expected exactly 1 NonSerializableClusterMessage issue');
        self::assertStringContains('UnregisteredMessage', $lines[0]);
    }

    #[Test]
    public function clusterMessageRuleAllowsRegisteredMessage(): void
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
