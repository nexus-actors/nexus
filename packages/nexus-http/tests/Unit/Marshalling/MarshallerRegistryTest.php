<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Marshalling\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarshallerRegistry::class)]
final class MarshallerRegistryTest extends TestCase
{
    #[Test]
    public function default_registers_json(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        self::assertSame('application/json', (string) $registry->default()->mediaType());
    }

    #[Test]
    public function negotiates_json_when_accept_is_star(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->negotiate('*/*');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function negotiates_highest_q_match(): void
    {
        $registry = (new MarshallerRegistry())
            ->register($this->jsonMarshaller());
        $m = $registry->negotiate('text/html;q=0.5, application/json;q=0.9');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function returns_default_when_no_match(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->negotiate('text/csv');
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function caches_negotiation_results(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $first = $registry->negotiate('application/json;q=1.0');
        $second = $registry->negotiate('application/json;q=1.0');
        self::assertSame($first, $second);
    }

    #[Test]
    public function lookup_by_media_type(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $m = $registry->byMediaType(MediaType::parse('application/json'));
        self::assertSame('application/json', (string) $m->mediaType());
    }

    private function jsonMarshaller(): JsonValinorMarshaller
    {
        return new JsonValinorMarshaller((new MapperBuilder())->mapper());
    }
}
