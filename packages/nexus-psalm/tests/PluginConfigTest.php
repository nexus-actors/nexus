<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests;

use Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule;
use Monadial\Nexus\Psalm\Plugin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

final class PluginConfigTest extends TestCase
{
    private const string DEAD_LETTER_REF = 'Monadial\Nexus\Core\Actor\DeadLetterRef';

    #[Test]
    public function defaultExcludeListContainsDeadLetterRef(): void
    {
        (new Plugin())(self::registration(), null);

        self::assertSame([self::DEAD_LETTER_REF], UntypedActorRefInjectionRule::excludedRefs());
    }

    #[Test]
    public function excludeRefConfigExtendsDefaults(): void
    {
        $config = new SimpleXMLElement(
            '<pluginClass>'
            . '<untypedActorRefInjection>'
            . '<excludeRef class="App\Infra\AuditSinkRef"/>'
            . '<excludeRef class="\App\Infra\FanOutRef"/>'
            . '</untypedActorRefInjection>'
            . '</pluginClass>',
        );

        (new Plugin())(self::registration(), $config);

        self::assertSame(
            [self::DEAD_LETTER_REF, 'App\Infra\AuditSinkRef', 'App\Infra\FanOutRef'],
            UntypedActorRefInjectionRule::excludedRefs(),
        );
    }

    protected function tearDown(): void
    {
        UntypedActorRefInjectionRule::configure([]);
    }

    private static function registration(): RegistrationInterface
    {
        return new class implements RegistrationInterface {
            public function addStubFile(string $file_name): void
            {
                // no-op: stub for unit testing Plugin config parsing
            }

            public function registerHooksFromClass(string $handler): void
            {
                // no-op: stub for unit testing Plugin config parsing
            }
        };
    }
}
