<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Test;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_key_exists;
use function explode;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-api
 *
 * PSR-7 ResponseInterface decorator with fluent test assertions.
 * Each assert* method returns $this for chaining; access the raw
 * response via psr() if you need to inspect anything outside this
 * shorthand surface.
 *
 * All assertions use PHPUnit\Framework\Assert directly, so failures
 * appear in the test output like any other PHPUnit assertion.
 */
final readonly class TestResponse
{
    public function __construct(private ResponseInterface $response) {}

    public function psr(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        $this->response->getBody()->rewind();

        return (string) $this->response->getBody();
    }

    /** @return array<array-key, mixed> */
    public function json(): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Response body is not a JSON object/array.');
        }

        return $decoded;
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            sprintf('Expected status %d, got %d. Body: %s', $expected, $this->status(), $this->body()),
        );

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertBadRequest(): self
    {
        return $this->assertStatus(400);
    }

    public function assertServerError(): self
    {
        Assert::assertGreaterThanOrEqual(
            500,
            $this->status(),
            sprintf('Expected 5xx, got %d', $this->status()),
        );

        return $this;
    }

    public function assertHeader(string $name, string $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->header($name),
            sprintf('Expected header %s=%s, got %s', $name, $expected, $this->header($name)),
        );

        return $this;
    }

    public function assertHeaderExists(string $name): self
    {
        Assert::assertTrue(
            $this->response->hasHeader($name),
            sprintf('Expected header %s to be present, but it was missing.', $name),
        );

        return $this;
    }

    public function assertBodyContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body());

        return $this;
    }

    /**
     * Assert a dot-path value in the JSON body.
     *
     *   $response->assertJsonPath('user.id', 42);
     *
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        /** @var mixed $actual */
        $actual = $this->extractJsonPath($path);
        Assert::assertSame(
            $expected,
            $actual,
            sprintf('JSON path %s mismatch. Body: %s', $path, $this->body()),
        );

        return $this;
    }

    public function assertJsonHasKey(string $path): self
    {
        Assert::assertNotNull(
            $this->extractJsonPath($path, throwOnMissing: false),
            sprintf('JSON path %s not present. Body: %s', $path, $this->body()),
        );

        return $this;
    }

    public function assertRedirectsTo(string $location): self
    {
        Assert::assertGreaterThanOrEqual(300, $this->status());
        Assert::assertLessThan(400, $this->status());
        Assert::assertSame($location, $this->header('Location'));

        return $this;
    }

    private function extractJsonPath(string $path, bool $throwOnMissing = true): mixed
    {
        /** @var mixed $current */
        $current = $this->json();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                if ($throwOnMissing) {
                    Assert::fail(sprintf('Path segment "%s" missing while resolving "%s".', $segment, $path));
                }

                return null;
            }

            /** @var mixed $current */
            $current = $current[$segment];
        }

        return $current;
    }
}
