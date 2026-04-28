<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonValinorMarshaller::class)]
final class JsonValinorMarshallerTest extends TestCase
{
    #[Test]
    public function media_type_is_application_json(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        self::assertSame('application/json', (string) $m->mediaType());
    }

    #[Test]
    public function marshals_arrays_as_json(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        self::assertSame('{"a":1}', $m->marshal(['a' => 1]));
    }

    #[Test]
    public function marshals_objects_via_get_object_vars(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $obj = new readonly class (1, 'x') {
            public function __construct(public int $id, public string $name) {}
        };
        self::assertSame('{"id":1,"name":"x"}', $m->marshal($obj));
    }

    #[Test]
    public function unmarshals_json_into_typed_value(): void
    {
        $m = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $cmd = $m->unmarshal('{"sku":"X","qty":3}', UnmarshalSample::class);

        self::assertInstanceOf(UnmarshalSample::class, $cmd);
        self::assertSame('X', $cmd->sku);
        self::assertSame(3, $cmd->qty);
    }
}

final readonly class UnmarshalSample
{
    public function __construct(public string $sku, public int $qty) {}
}
