<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Exception;

use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

#[CoversClass(ExceptionMapperRegistry::class)]
#[CoversClass(DefaultMappers::class)]
final class ExceptionMapperRegistryTest extends TestCase
{
    #[Test]
    public function maps_exact_class_match(): void
    {
        $registry = new ExceptionMapperRegistry();
        $registry->register(RuntimeException::class, static fn(): ResponseInterface => Response::badRequest());

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(400, $r->getStatusCode());
    }

    #[Test]
    public function falls_back_to_parent_class_mapper(): void
    {
        $registry = new ExceptionMapperRegistry();
        $registry->register(Throwable::class, static fn(): ResponseInterface => Response::internalServerError());

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(500, $r->getStatusCode());
    }

    #[Test]
    public function http_exception_uses_carried_status(): void
    {
        $registry = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($registry, ErrorMode::Production);

        $r = $registry->map(HttpException::notFound('nope'), new ServerRequest('GET', '/'));
        self::assertSame(404, $r->getStatusCode());
        self::assertSame('nope', (string) $r->getBody());
    }

    #[Test]
    public function dev_mode_includes_trace_in_500_body(): void
    {
        $registry = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($registry, ErrorMode::Development);

        $r = $registry->map(new RuntimeException('boom'), new ServerRequest('GET', '/'));
        self::assertSame(500, $r->getStatusCode());
        self::assertStringContainsString('"boom"', (string) $r->getBody());
        self::assertStringContainsString('"trace"', (string) $r->getBody());
    }
}
