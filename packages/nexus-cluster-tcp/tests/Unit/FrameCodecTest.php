<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use Monadial\Nexus\Cluster\Tcp\Exception\ProtocolException;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameCodec;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Frame::class)]
#[CoversClass(FrameCodec::class)]
#[CoversClass(FrameType::class)]
#[CoversClass(ProtocolException::class)]
final class FrameCodecTest extends TestCase
{
    private FrameCodec $codec;

    #[Test]
    public function encodeProducesLengthPrefixedFrame(): void
    {
        $frame = new Frame(FrameType::Ping, '');
        $encoded = $this->codec->encode($frame);

        // Length = 1 (type byte) + 0 (payload) = 1; type byte = chr(5)
        self::assertSame(pack('N', 1) . chr(5), $encoded);
        self::assertSame(5, strlen($encoded));
    }

    #[Test]
    public function encodeIncludesPayloadAfterTypeByte(): void
    {
        $frame = new Frame(FrameType::Message, 'hello');
        $encoded = $this->codec->encode($frame);

        // Length = 1 + 5 = 6
        $expected = pack('N', 6) . chr(FrameType::Message->value) . 'hello';
        self::assertSame($expected, $encoded);
    }

    #[Test]
    public function allFrameTypesRoundTrip(): void
    {
        foreach (FrameType::cases() as $type) {
            $payload = 'payload-' . $type->name;
            $frame = new Frame($type, $payload);
            $encoded = $this->codec->encode($frame);
            $result = $this->codec->decodeStream($encoded);

            self::assertCount(1, $result['frames'], "Frame type {$type->name} failed round-trip");
            self::assertSame($type, $result['frames'][0]->type);
            self::assertSame($payload, $result['frames'][0]->payload);
            self::assertSame('', $result['rest']);
        }
    }

    #[Test]
    public function emptyPayloadPingRoundTrips(): void
    {
        $frame = new Frame(FrameType::Ping, '');
        $encoded = $this->codec->encode($frame);
        $result = $this->codec->decodeStream($encoded);

        self::assertCount(1, $result['frames']);
        self::assertSame(FrameType::Ping, $result['frames'][0]->type);
        self::assertSame('', $result['frames'][0]->payload);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function emptyPayloadPongRoundTrips(): void
    {
        $frame = new Frame(FrameType::Pong, '');
        $encoded = $this->codec->encode($frame);
        $result = $this->codec->decodeStream($encoded);

        self::assertCount(1, $result['frames']);
        self::assertSame(FrameType::Pong, $result['frames'][0]->type);
        self::assertSame('', $result['frames'][0]->payload);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function decodeStreamWithMultipleFrames(): void
    {
        $ping = new Frame(FrameType::Ping, '');
        $pong = new Frame(FrameType::Pong, '');
        $message = new Frame(FrameType::Message, 'msgpack-body');

        $buffer = $this->codec->encode($ping)
            . $this->codec->encode($pong)
            . $this->codec->encode($message);
        $result = $this->codec->decodeStream($buffer);

        self::assertCount(3, $result['frames']);
        self::assertSame(FrameType::Ping, $result['frames'][0]->type);
        self::assertSame(FrameType::Pong, $result['frames'][1]->type);
        self::assertSame(FrameType::Message, $result['frames'][2]->type);
        self::assertSame('msgpack-body', $result['frames'][2]->payload);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function partialLengthPrefixStaysInRest(): void
    {
        $frame = new Frame(FrameType::Message, 'hello');
        $encoded = $this->codec->encode($frame);

        // Only 3 bytes — partial 4-byte length prefix
        $partial = substr($encoded, 0, 3);
        $result = $this->codec->decodeStream($partial);

        self::assertCount(0, $result['frames']);
        self::assertSame($partial, $result['rest']);
    }

    #[Test]
    public function partialPayloadStaysInRest(): void
    {
        $frame = new Frame(FrameType::Message, 'hello world');
        $encoded = $this->codec->encode($frame);

        // 4 length bytes + 1 type byte + 2 payload bytes — payload incomplete
        $partial = substr($encoded, 0, 7);
        $result = $this->codec->decodeStream($partial);

        self::assertCount(0, $result['frames']);
        self::assertSame($partial, $result['rest']);
    }

    #[Test]
    public function byteByByteFeedingReassemblesFrame(): void
    {
        $frame = new Frame(FrameType::Handshake, 'msgpack-bytes');
        $encoded = $this->codec->encode($frame);
        $totalBytes = strlen($encoded);

        $buffer = '';
        $frames = [];

        for ($i = 0; $i < $totalBytes; $i++) {
            $buffer .= $encoded[$i];
            $result = $this->codec->decodeStream($buffer);
            $frames = array_merge($frames, $result['frames']);
            $buffer = $result['rest'];

            if ($i < $totalBytes - 1) {
                self::assertCount(
                    0,
                    $result['frames'],
                    "Expected no frames after byte {$i} (only {$i}/{$totalBytes} bytes fed)",
                );
            }
        }

        self::assertCount(1, $frames);
        self::assertSame(FrameType::Handshake, $frames[0]->type);
        self::assertSame('msgpack-bytes', $frames[0]->payload);
        self::assertSame('', $buffer);
    }

    #[Test]
    public function completeFrameFollowedByPartialRemainderInRest(): void
    {
        $ping = new Frame(FrameType::Ping, '');
        $message = new Frame(FrameType::Message, 'payload');

        $pingEncoded = $this->codec->encode($ping);
        $messageEncoded = $this->codec->encode($message);

        // Complete ping + partial of message frame (only 3 bytes)
        $buffer = $pingEncoded . substr($messageEncoded, 0, 3);
        $result = $this->codec->decodeStream($buffer);

        self::assertCount(1, $result['frames']);
        self::assertSame(FrameType::Ping, $result['frames'][0]->type);
        self::assertSame(substr($messageEncoded, 0, 3), $result['rest']);
    }

    #[Test]
    public function emptyBufferReturnsNoFrames(): void
    {
        $result = $this->codec->decodeStream('');

        self::assertCount(0, $result['frames']);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function oversizeDeclaredLengthThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);

        // Declare a 9 MiB frame body — exceeds default 8 MiB limit
        $buffer = pack('N', 9 * 1024 * 1024);
        $this->codec->decodeStream($buffer);
    }

    #[Test]
    public function customMaxFrameSizeIsRespected(): void
    {
        $this->expectException(ProtocolException::class);

        $smallCodec = new FrameCodec(100);
        // Declare 101 bytes — over the 100-byte limit
        $buffer = pack('N', 101);
        $smallCodec->decodeStream($buffer);
    }

    #[Test]
    public function unknownTypeByteIsSkippedForForwardCompatibility(): void
    {
        // A well-framed frame carrying an unknown type byte (99 is not in FrameType) is SKIPPED,
        // not treated as an error — an older node must tolerate a frame type a newer protocol
        // version introduced rather than tear the link down. A following known frame is still
        // decoded, proving the stream stayed synchronized across the skip.
        $unknown = pack('N', 2) . chr(99) . 'x';
        $known = $this->codec->encode(new Frame(FrameType::Gossip, 'g'));

        $result = $this->codec->decodeStream($unknown . $known);

        self::assertCount(1, $result['frames']);
        self::assertSame(FrameType::Gossip, $result['frames'][0]->type);
        self::assertSame('g', $result['frames'][0]->payload);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function encodeRejectsFrameLargerThanMaxFrameSize(): void
    {
        $codec = new FrameCodec(100);

        // 100-byte payload + 1-byte type = 101-byte body, one over the limit. encode() must throw
        // locally so the sender sees it and no oversized frame reaches the wire to detonate the link.
        $this->expectException(ProtocolException::class);

        $codec->encode(new Frame(FrameType::Message, str_repeat('x', 100)));
    }

    #[Test]
    public function binaryPayloadRoundTrips(): void
    {
        $binaryPayload = "\x00\x01\x02\xff\xfe\xfd";
        $frame = new Frame(FrameType::Message, $binaryPayload);
        $encoded = $this->codec->encode($frame);
        $result = $this->codec->decodeStream($encoded);

        self::assertCount(1, $result['frames']);
        self::assertSame($binaryPayload, $result['frames'][0]->payload);
    }

    #[Test]
    public function frameAtExactMaxFrameSizeBoundaryIsAccepted(): void
    {
        $codec = new FrameCodec(100);
        // Frame with 99-byte payload + 1-byte type = 100-byte body (exactly at boundary)
        $payload = str_repeat('x', 99);
        $frame = new Frame(FrameType::Message, $payload);
        $encoded = $codec->encode($frame);

        $result = $codec->decodeStream($encoded);

        self::assertCount(1, $result['frames']);
        self::assertSame(FrameType::Message, $result['frames'][0]->type);
        self::assertSame($payload, $result['frames'][0]->payload);
        self::assertSame('', $result['rest']);
    }

    #[Test]
    public function zeroLengthFrameBodyThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);

        // Frame body length of 0 (invalid; minimum is 1 for type byte)
        $buffer = pack('N', 0);
        $this->codec->decodeStream($buffer);
    }

    protected function setUp(): void
    {
        $this->codec = new FrameCodec();
    }
}
