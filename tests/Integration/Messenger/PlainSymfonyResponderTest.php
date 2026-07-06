<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Ask\ReplyChannel;
use Monadial\Nexus\Messenger\Ask\ReplyChannelFactory;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Pong;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

use function serialize;

/**
 * Interop proof: a responder that is NOT a Nexus consumer — plain Symfony
 * Messenger wire handling only — can answer an ask() using nothing but the
 * two documented headers, X-Nexus-Correlation-Id and X-Nexus-Reply-To.
 *
 * The NexusMessengerSerializer stands in for the wire on both hops: encode()
 * yields exactly the body + headers a broker would hand to a foreign worker,
 * and decode() is what the reply channel's serializer would produce from the
 * foreign worker's encoded reply. No Nexus consumer classes (ReceiverActor,
 * MessengerReplyRef, ReplySenderLocator) appear on the responder path.
 */
#[CoversClass(NexusMessengerSerializer::class)]
final class PlainSymfonyResponderTest extends TestCase
{
    #[Test]
    public function plainSymfonyResponderAnswersAskUsingOnlyDocumentedHeaders(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('plain-responder', $runtime);

        $requestTransport = new InMemoryTransport();
        $replyTransport = new InMemoryTransport();
        $channelName = 'replies';

        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);
        $registry->register(Pong::class, 'pong');
        $serializer = new NexusMessengerSerializer(
            new PhpNativeSerializer([Ping::class, Pong::class]),
            $registry,
        );

        $askSupport = MessengerBridge::askSupport(
            $system,
            $this->makeFactory($replyTransport, $channelName),
        );
        $ref = MessengerBridge::producer($requestTransport, 'orders-out', askSupport: $askSupport);

        /** @var Pong|null $reply */
        $reply = null;

        $runtime->spawn(static function () use ($ref, &$reply): void {
            /** @var Pong $result */
            $result = $ref->ask(new Ping('interop'), Duration::seconds(3))->await();
            $reply = $result;
        });

        /** @var array<string, string>|null $wireHeaders */
        $wireHeaders = null;

        // Foreign responder: reads the request off the wire, resolves the reply
        // destination through its OWN configured map (the wire value is only a
        // lookup key — never a DSN; SSRF hardening), and publishes a reply built
        // as a plain encoded array with the correlation header copied over.
        $runtime->scheduleOnce(
            Duration::millis(100),
            static function () use ($requestTransport, $replyTransport, $serializer, $channelName, &$wireHeaders): void {
                $replySenders = [$channelName => $replyTransport];

                foreach ($requestTransport->get() as $requestEnvelope) {
                    $encoded = $serializer->encode($requestEnvelope);
                    $headers = $encoded['headers'];
                    $wireHeaders = $headers;

                    $correlationId = $headers['X-Nexus-Correlation-Id'] ?? null;
                    $replyTo = $headers['X-Nexus-Reply-To'] ?? null;

                    if ($correlationId === null || $replyTo === null) {
                        continue;
                    }

                    $sender = $replySenders[$replyTo] ?? null;

                    if ($sender === null) {
                        continue;
                    }

                    $encodedReply = [
                        'body' => serialize(new Pong('foreign-pong')),
                        'headers' => [
                            'type' => 'pong',
                            'X-Nexus-Correlation-Id' => $correlationId,
                        ],
                    ];

                    $sender->send($serializer->decode($encodedReply));
                    $requestTransport->ack($requestEnvelope);
                }
            },
        );

        $runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // The foreign consumer saw both documented headers on the wire.
        self::assertNotNull($wireHeaders, 'Foreign responder must have seen the request on the wire');
        self::assertArrayHasKey('X-Nexus-Correlation-Id', $wireHeaders);
        self::assertSame($channelName, $wireHeaders['X-Nexus-Reply-To'] ?? null);

        // The Future resolved with the foreign-built reply.
        self::assertInstanceOf(Pong::class, $reply, 'Future must resolve with the foreign-built reply');
        self::assertSame('foreign-pong', $reply->body);
        self::assertCount(1, $requestTransport->getAcknowledged());
    }

    private function makeFactory(InMemoryTransport $replyTransport, string $channelName): ReplyChannelFactory
    {
        return new class ($replyTransport, $channelName) implements ReplyChannelFactory {
            public function __construct(private readonly InMemoryTransport $transport, private readonly string $name) {}

            public function create(): ReplyChannel
            {
                $transport = $this->transport;
                $name = $this->name;

                return new class ($transport, $name) implements ReplyChannel {
                    public function __construct(
                        private readonly InMemoryTransport $transport,
                        private readonly string $name,
                    ) {}

                    public function name(): string
                    {
                        return $this->name;
                    }

                    public function receiver(): ReceiverInterface
                    {
                        return $this->transport;
                    }

                    public function close(): void
                    {
                        // No-op: InMemoryTransport has no lifecycle to clean up.
                    }
                };
            }
        };
    }
}
