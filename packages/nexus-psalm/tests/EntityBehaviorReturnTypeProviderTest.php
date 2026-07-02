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

final class EntityBehaviorReturnTypeProviderTest extends TestCase
{
    private const string PSALM_BIN = 'vendor/bin/psalm';

    #[Test]
    public function hookInfersBothGenericsFromFullyTypedCreate(): void
    {
        $output = $this->runPsalmOnFixture('EntityBehaviorCreateFixture.php');

        // fullyTypedCreateReturnsBothGenerics() declares
        // EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem> and returns
        // EntityBehavior::create(...) with typed class-string and closure.
        // Hook must rewrite the return to match; broken hook →
        // MoreSpecificReturnType / LessSpecificReturnStatement.
        $issues = $this->filterIssueLines($output, 'fullyTypedCreateReturnsBothGenerics');

        self::assertEmpty(
            $issues,
            "Expected fullyTypedCreateReturnsBothGenerics to be clean — hook should infer EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>:\n"
            . implode("\n", $issues),
        );
    }

    #[Test]
    public function hookFallsThroughWhenCommandParamIsObject(): void
    {
        $output = $this->runPsalmOnFixture('EntityBehaviorCreateFixture.php');

        // objectCommandFallsThroughToDefault() uses `object $cmd`.
        // Hook returns null → Psalm's own template inference takes over.
        // The @psalm-trace pin confirms the builder type is still visible
        // (containing EntityBehaviorBuilder) rather than being lost entirely.
        $traces = $this->filterIssueLines($output, 'Trace');

        $found = false;

        foreach ($traces as $line) {
            if (str_contains($line, 'EntityBehaviorBuilder') && str_contains($line, 'FixtureOrder')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected @psalm-trace to report an EntityBehaviorBuilder type with FixtureOrder for the object-param fall-through case:\n"
            . implode("\n", $traces),
        );
    }

    #[Test]
    public function hookFlagsMismatchedEntityGeneric(): void
    {
        $output = $this->runPsalmOnFixture('EntityBehaviorCreateFixture.php');

        // mismatchedEntityGenericFails() declares
        // EntityBehaviorBuilder<FixtureUnrelatedCommand, FixtureAddLineItem>
        // but the hook rewrites the actual return to
        // EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>.
        // Psalm MUST report InvalidReturnStatement mentioning both.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        $found = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'FixtureOrder') && str_contains($line, 'FixtureUnrelatedCommand')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected InvalidReturnStatement mentioning FixtureOrder (actual) and FixtureUnrelatedCommand (declared):\n"
            . implode("\n", $lines),
        );
    }

    #[Test]
    public function hookFlagsMismatchedCommandGeneric(): void
    {
        $output = $this->runPsalmOnFixture('EntityBehaviorCreateFixture.php');

        // mismatchedCommandGenericFails() declares
        // EntityBehaviorBuilder<FixtureOrder, FixtureUnrelatedCommand>
        // but the hook rewrites the actual return to
        // EntityBehaviorBuilder<FixtureOrder, FixtureAddLineItem>.
        // Psalm MUST report InvalidReturnStatement mentioning both.
        $lines = $this->filterIssueLines($output, 'InvalidReturnStatement');

        $found = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'FixtureAddLineItem') && str_contains($line, 'FixtureUnrelatedCommand')) {
                $found = true;

                break;
            }
        }

        self::assertTrue(
            $found,
            "Expected InvalidReturnStatement mentioning FixtureAddLineItem (actual) and FixtureUnrelatedCommand (declared):\n"
            . implode("\n", $lines),
        );
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
}
