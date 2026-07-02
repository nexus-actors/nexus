<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Http\Ws;

use Monadial\Nexus\Example\TicTacToe\Http\Ws\ClientFrameCodec;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\ForfeitIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\JoinIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\MoveIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\SnapshotIntent;
use Monadial\Nexus\Serialization\ValinorJsonSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_map;

#[CoversClass(ClientFrameCodec::class)]
#[CoversClass(MoveIntent::class)]
#[CoversClass(JoinIntent::class)]
final class ClientFrameCodecTest extends TestCase
{
    private ClientFrameCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new ClientFrameCodec(new ValinorJsonSerializer());
    }

    #[Test]
    public function it_decodes_each_well_formed_intent(): void
    {
        self::assertInstanceOf(JoinIntent::class, $this->codec->decode('{"type":"join","name":"Alice"}'));
        self::assertInstanceOf(MoveIntent::class, $this->codec->decode('{"type":"move","cell":4}'));
        self::assertInstanceOf(ForfeitIntent::class, $this->codec->decode('{"type":"forfeit"}'));
        self::assertInstanceOf(SnapshotIntent::class, $this->codec->decode('{"type":"snapshot"}'));
    }

    #[Test]
    public function a_move_intent_structurally_cannot_carry_a_player_id(): void
    {
        // The whole identity model rests on this: a gameplay frame decodes into
        // a value object that has nowhere to put a client-asserted player id.
        $props = array_map(
            static fn($p): string => $p->getName(),
            (new ReflectionClass(MoveIntent::class))->getProperties(),
        );

        self::assertSame(['cell'], $props);
    }

    #[Test]
    public function an_injected_player_id_never_reaches_a_command(): void
    {
        // Whether the mapper rejects the extra key or ignores it, no player id
        // can survive: MoveIntent has only `cell`.
        $intent = $this->codec->decode('{"type":"move","cell":4,"playerId":"attacker"}');

        self::assertTrue($intent === null || $intent instanceof MoveIntent);

        if ($intent instanceof MoveIntent) {
            self::assertSame(4, $intent->cell);
        }
    }

    #[Test]
    public function unknown_malformed_and_non_object_frames_decode_to_null(): void
    {
        self::assertNull($this->codec->decode('{"type":"nope"}'));
        self::assertNull($this->codec->decode('not json at all'));
        self::assertNull($this->codec->decode('[1,2,3]'));
        self::assertNull($this->codec->decode('{"cell":4}'));
    }

    #[Test]
    public function an_out_of_range_move_is_rejected(): void
    {
        self::assertNull($this->codec->decode('{"type":"move","cell":99}'));
    }
}
