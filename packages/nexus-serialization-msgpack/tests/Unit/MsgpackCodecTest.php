<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack\Tests\Unit;

use MessagePack\Packer;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use UnexpectedValueException;

#[CoversClass(MsgpackCodec::class)]
final class MsgpackCodecTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Pure-PHP backend (rybakit/msgpack)
    // -------------------------------------------------------------------------

    #[Test]
    public function purePathEmptyArray(): void
    {
        $codec = new MsgpackCodec(false);

        self::assertSame([], $codec->unpack($codec->pack([])));
    }

    #[Test]
    public function purePathNestedArrays(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['outer' => ['inner' => ['deep' => 'value']]];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathIntegers(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['large' => PHP_INT_MAX, 'negative' => -99, 'positive' => 12345, 'zero' => 0];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathFloats(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['negative' => -0.001, 'pi' => 3.14159];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathStrings(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['ascii' => 'hello world', 'utf8' => 'héllo wörld 日本語'];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathBooleans(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['no' => false, 'yes' => true];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathNullValues(): void
    {
        $codec = new MsgpackCodec(false);
        $data = ['nul' => null];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    public function purePathNonArrayUnpackThrowsUnexpectedValueException(): void
    {
        $codec = new MsgpackCodec(false);
        // Pack a bare integer via rybakit directly — the codec must reject it.
        $scalarBytes = (new Packer())->pack(42);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected array from msgpack decoding, got int.');

        $codec->unpack($scalarBytes);
    }

    #[Test]
    public function purePathGarbageBytesThrow(): void
    {
        $codec = new MsgpackCodec(false);
        // 0xcd = uint16 format which requires 2 more bytes; truncated → InsufficientDataException.
        $this->expectException(Throwable::class);

        $codec->unpack("\xcd");
    }

    #[Test]
    public function purePathTrailingBytesThrowsUnexpectedValueException(): void
    {
        $codec = new MsgpackCodec(false);
        // Pack a valid array, then append garbage to create trailing bytes.
        $validArray = $codec->pack(['id' => 42]);
        $malformedBytes = $validArray . "\xff";

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unexpected trailing bytes after msgpack value');

        $codec->unpack($malformedBytes);
    }

    // -------------------------------------------------------------------------
    // Native ext-msgpack backend (skipped when the extension is absent)
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('msgpack')]
    public function extPathEmptyArray(): void
    {
        $codec = new MsgpackCodec(true);

        self::assertSame([], $codec->unpack($codec->pack([])));
    }

    #[Test]
    #[RequiresPhpExtension('msgpack')]
    public function extPathRoundTrip(): void
    {
        $codec = new MsgpackCodec(true);
        $data = ['flag' => true, 'id' => 7, 'score' => 9.5, 'type' => 'test.event'];

        self::assertSame($data, $codec->unpack($codec->pack($data)));
    }

    #[Test]
    #[RequiresPhpExtension('msgpack')]
    public function extPathNonArrayThrows(): void
    {
        $codec = new MsgpackCodec(true);

        $this->expectException(UnexpectedValueException::class);

        $codec->unpack(msgpack_pack(42));
    }
}
