<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Tests\Unit\Http\Ws;

use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\PlayerMark;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\View\PlayerSeat;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\SeatView;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\SnapshotPayload;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\WireEnvelope;
use Monadial\Nexus\Serialization\ValinorJsonSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function property_exists;
use function str_contains;

/**
 * The broadcast privacy boundary. A seat's id is a capability token; it must
 * never reach other clients. These tests lock the wire shape so a future
 * refactor cannot quietly re-expose it.
 */
#[CoversClass(SnapshotPayload::class)]
#[CoversClass(SeatView::class)]
final class SnapshotPayloadTest extends TestCase
{
    private const string SEAT_TOKEN_X = '01JXSECRETTOKENFORPLAYERX0';
    private const string SEAT_TOKEN_O = '01JXSECRETTOKENFORPLAYERO0';

    #[Test]
    public function the_wire_seat_carries_a_name_but_has_no_id_field(): void
    {
        $payload = SnapshotPayload::of($this->snapshot());

        self::assertInstanceOf(SeatView::class, $payload->playerX);
        self::assertSame('Alice', $payload->playerX->name);
        self::assertFalse(
            property_exists(SeatView::class, 'id'),
            'SeatView must never carry the seat token',
        );
    }

    #[Test]
    public function serialised_snapshot_contains_names_but_not_tokens(): void
    {
        $serializer = new ValinorJsonSerializer();

        $json = $serializer->serialize(new WireEnvelope('snapshot', SnapshotPayload::of($this->snapshot())));

        self::assertTrue(str_contains($json, 'Alice'), 'player name should be broadcast');
        self::assertTrue(str_contains($json, 'Bob'), 'player name should be broadcast');
        self::assertFalse(str_contains($json, self::SEAT_TOKEN_X), 'seat token X leaked onto the wire');
        self::assertFalse(str_contains($json, self::SEAT_TOKEN_O), 'seat token O leaked onto the wire');
    }

    private function snapshot(): GameSnapshot
    {
        return new GameSnapshot(
            gameId: '01JXGAME',
            status: GameStatus::InProgress,
            playerX: new PlayerSeat(self::SEAT_TOKEN_X, 'Alice'),
            playerO: new PlayerSeat(self::SEAT_TOKEN_O, 'Bob'),
            board: [null, null, null, null, 'X', null, null, null, null],
            nextTurn: PlayerMark::O,
            winner: null,
        );
    }
}
