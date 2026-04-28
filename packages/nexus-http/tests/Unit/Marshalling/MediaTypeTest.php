<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use Monadial\Nexus\Http\Marshalling\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaType::class)]
final class MediaTypeTest extends TestCase
{
    #[Test]
    public function parses_application_json(): void
    {
        $mt = MediaType::parse('application/json');
        self::assertSame('application', $mt->type);
        self::assertSame('json', $mt->subtype);
        self::assertSame('application/json', (string) $mt);
    }

    #[Test]
    public function parses_with_parameters(): void
    {
        $mt = MediaType::parse('text/html; charset=utf-8');
        self::assertSame('text', $mt->type);
        self::assertSame('html', $mt->subtype);
        self::assertSame(['charset' => 'utf-8'], $mt->params);
    }

    #[Test]
    public function equality_ignores_params(): void
    {
        self::assertTrue(
            MediaType::parse('application/json')->matches(
                MediaType::parse('application/json; charset=utf-8'),
            ),
        );
    }

    #[Test]
    public function wildcard_subtype_matches_anything_in_type(): void
    {
        self::assertTrue(MediaType::parse('application/*')->matches(MediaType::parse('application/json')));
        self::assertFalse(MediaType::parse('application/*')->matches(MediaType::parse('text/html')));
    }
}
