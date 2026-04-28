<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\formBody;
use function Monadial\Nexus\Http\jsonBody;
use function Monadial\Nexus\Http\rawBody;
use function strlen;

final class BodyTest extends TestCase
{
    #[Test]
    public function raw_body_passes_string(): void
    {
        $route = rawBody(static fn(string $b) => complete(['len' => strlen($b)]));
        $response = ($route->run)(CtxFactory::with('POST', '/', 'hello'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"len":5', (string) $response->getBody());
    }

    #[Test]
    public function json_body_unmarshals_to_target(): void
    {
        $route = jsonBody(
            CreateOrderSample::class,
            static fn(CreateOrderSample $cmd) => complete(['q' => $cmd->qty, 's' => $cmd->sku]),
        );
        $response = ($route->run)(CtxFactory::with('POST', '/', '{"sku":"X","qty":3}'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"s":"X"', (string) $response->getBody());
        self::assertStringContainsString('"q":3', (string) $response->getBody());
    }

    #[Test]
    public function form_body_passes_parsed_array(): void
    {
        $route = formBody(static fn(array $form) => complete($form));
        $response = ($route->run)(CtxFactory::with('POST', '/', 'a=1&b=2'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"a":"1"', (string) $response->getBody());
        self::assertStringContainsString('"b":"2"', (string) $response->getBody());
    }
}

final readonly class CreateOrderSample
{
    public function __construct(public string $sku, public int $qty) {}
}
