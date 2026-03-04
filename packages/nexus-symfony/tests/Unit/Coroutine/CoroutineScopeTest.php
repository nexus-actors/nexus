<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Coroutine;

use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoroutineScope::class)]
final class CoroutineScopeTest extends TestCase
{
    private CoroutineScope $scope;

    #[Test]
    public function getReturnsInitialisedService(): void
    {
        $service = new \stdClass();
        $this->scope->initialize(['key' => static fn() => $service]);

        self::assertSame($service, $this->scope->get('key'));
    }

    #[Test]
    public function getThrowsWhenServiceNotInScope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service "missing" not in coroutine scope');

        $this->scope->get('missing');
    }

    #[Test]
    public function factoryCalledOncePerInitialise(): void
    {
        $callCount = 0;
        $this->scope->initialize(['key' => static function () use (&$callCount): object {
            $callCount++;

            return new \stdClass();
        }]);

        $this->scope->get('key');
        $this->scope->get('key');

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function initializeOverwritesPreviousScope(): void
    {
        $first  = new \stdClass();
        $second = new \stdClass();

        $this->scope->initialize(['key' => static fn() => $first]);
        $this->scope->initialize(['key' => static fn() => $second]);

        self::assertSame($second, $this->scope->get('key'));
    }

    protected function setUp(): void
    {
        $this->scope = new CoroutineScope(new MockCoroutineContext());
    }
}
