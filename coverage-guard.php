<?php

declare(strict_types=1);

use ShipMonk\CoverageGuard\Config;
use ShipMonk\CoverageGuard\Hierarchy\CodeBlock;
use ShipMonk\CoverageGuard\Rule\CoverageError;
use ShipMonk\CoverageGuard\Rule\CoverageRule;
use ShipMonk\CoverageGuard\Rule\EnforceCoverageForMethodsRule;
use ShipMonk\CoverageGuard\Rule\InspectionContext;

/**
 * Wraps EnforceCoverageForMethodsRule with path-based exclusions.
 */
final class FilteredCoverageRule implements CoverageRule
{
    private readonly EnforceCoverageForMethodsRule $inner;

    /**
     * @param list<non-empty-string> $excludePatterns Regex patterns for file paths to skip
     */
    public function __construct(
        int $requiredCoveragePercentage,
        int $minExecutableLines,
        private readonly array $excludePatterns,
    ) {
        $this->inner = new EnforceCoverageForMethodsRule($requiredCoveragePercentage, $minExecutableLines);
    }

    #[Override]
    public function inspect(CodeBlock $codeBlock, InspectionContext $context): ?CoverageError
    {
        $filePath = $context->getFilePath();

        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $filePath) === 1) {
                return null;
            }
        }

        return $this->inner->inspect($codeBlock, $context);
    }
}

$config = new Config();

$config->addRule(new FilteredCoverageRule(
    requiredCoveragePercentage: 90,
    minExecutableLines: 1,
    excludePatterns: [
        // Runtime packages are covered by integration tests, not unit tests
        '#nexus-runtime-swoole/#',
        '#nexus-runtime-fiber/#',
        '#nexus-runtime-step/#',
        '#nexus-cluster-swoole-thread/#',
        // Test support classes are helpers, not production code
        '#/tests/Support/#',
        '#/tests/Fixture/#',
        // Doctrine entities are data objects with trivial constructors
        '#nexus-persistence-doctrine/src/Entity/#',
    ],
));

$config->addCoveragePathMapping('/app', __DIR__);

return $config;
