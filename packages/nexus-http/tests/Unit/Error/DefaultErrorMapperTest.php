<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Error;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Rejection\BodyParseException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteNotFoundRejection;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(DefaultErrorMapper::class)]
final class DefaultErrorMapperTest extends TestCase
{
    private DefaultErrorMapper $mapper;

    #[Test]
    public function maps_extractor_rejection_to_400(): void
    {
        $response = $this->mapper->map(
            new ExtractorRejection('orders/abc', 'expected integer'),
            CtxFactory::with('GET', '/'),
        );
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('extractor_failed', (string) $response->getBody());
    }

    #[Test]
    public function maps_body_parse_to_400(): void
    {
        $response = $this->mapper->map(new BodyParseException('bad json'), CtxFactory::with('POST', '/'));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function maps_not_found_to_404(): void
    {
        $response = $this->mapper->map(new RouteNotFoundRejection('/missing'), CtxFactory::with('GET', '/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function maps_method_not_allowed_to_405_with_allow_header(): void
    {
        $response = $this->mapper->map(
            new MethodNotAllowedRejection('PATCH', ['GET', 'POST']),
            CtxFactory::with('PATCH', '/'),
        );
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function maps_ask_timeout_to_504(): void
    {
        $exception = new AskTimeoutException(ActorPath::fromString('/orders'), Duration::seconds(1));
        $response = $this->mapper->map($exception, CtxFactory::with('GET', '/'));
        self::assertSame(504, $response->getStatusCode());
    }

    #[Test]
    public function maps_mailbox_closed_to_503(): void
    {
        $response = $this->mapper->map(new MailboxClosedException(), CtxFactory::with('GET', '/'));
        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function maps_unknown_throwable_to_500(): void
    {
        $response = $this->mapper->map(new RuntimeException('boom'), CtxFactory::with('GET', '/'));
        self::assertSame(500, $response->getStatusCode());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->mapper = new DefaultErrorMapper();
    }
}
