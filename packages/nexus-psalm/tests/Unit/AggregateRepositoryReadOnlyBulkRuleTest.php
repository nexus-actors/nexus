<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function implode;

final class AggregateRepositoryReadOnlyBulkRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsUnannotatedIterableMethodsOnRepositories(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/AggregateRepositoryReadOnlyBulkFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('AggregateRepositoryReadOnlyBulk', $report);
        self::assertStringContainsString('BadIterableRepository', $report);
        self::assertStringContainsString('BadArrayRepository', $report);
        self::assertStringContainsString('BadIteratorRepository', $report);
        self::assertStringNotContainsString('GoodMinimalRepository', $report);
        self::assertStringNotContainsString('GoodBulkRepository', $report);
        self::assertStringNotContainsString('RepoFixtureRegularService', $report);
    }
}
