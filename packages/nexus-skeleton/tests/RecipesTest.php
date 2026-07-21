<?php

declare(strict_types=1);

namespace App\Tests;

use App\Setup\Recipes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecipesTest extends TestCase
{
    #[Test]
    public function catalog_contains_every_wizard_module(): void
    {
        $keys = array_keys(Recipes::all());
        sort($keys);

        self::assertSame(
            ['cluster', 'http', 'messenger', 'otel', 'persistence-dbal', 'persistence-doctrine', 'persistence-memory', 'swoole'],
            $keys,
        );
    }

    #[Test]
    public function experimental_flags_match_stability_matrix(): void
    {
        self::assertTrue(Recipes::get('cluster')->experimental);
        self::assertTrue(Recipes::get('messenger')->experimental);
        self::assertFalse(Recipes::get('persistence-dbal')->experimental);
        self::assertFalse(Recipes::get('persistence-doctrine')->experimental);
        self::assertFalse(Recipes::get('persistence-memory')->experimental);
        self::assertFalse(Recipes::get('swoole')->experimental);
        self::assertFalse(Recipes::get('otel')->experimental);
    }

    #[Test]
    public function every_recipe_declares_packages_and_doc_url(): void
    {
        foreach (Recipes::all() as $recipe) {
            self::assertNotEmpty($recipe->packages);
            self::assertStringStartsWith('https://docs.nexusactors.com/', $recipe->docUrl);
        }
    }

    #[Test]
    public function swoole_recipe_overwrites_runtime_config(): void
    {
        $swoole = Recipes::get('swoole');

        self::assertSame('runtime.php', $swoole->configFile);
        self::assertStringContainsString('SwooleRuntime', (string) $swoole->configTemplate);
    }
}
