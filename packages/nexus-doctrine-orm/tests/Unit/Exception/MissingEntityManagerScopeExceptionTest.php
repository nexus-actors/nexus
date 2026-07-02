<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingEntityManagerScopeException::class)]
final class MissingEntityManagerScopeExceptionTest extends TestCase
{
    #[Test]
    public function extendsNexusException(): void
    {
        $e = new MissingEntityManagerScopeException();
        self::assertInstanceOf(NexusException::class, $e);
        self::assertStringContainsString('EntityManagerScopeMiddleware', $e->getMessage());
    }
}
