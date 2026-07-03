<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Boot;

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\FulfillmentConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;

#[CoversClass(FulfillmentConfig::class)]
final class FulfillmentConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FULFILLMENT_HTTP_PORT');
        putenv('FULFILLMENT_DB_HOST');
    }

    #[Test]
    public function defaultsAreProductionContainerValues(): void
    {
        $config = FulfillmentConfig::fromEnv();

        self::assertSame('0.0.0.0', $config->http->host);
        self::assertSame(8080, $config->http->port);
        self::assertSame(1, $config->http->workers);
        self::assertSame('db', $config->db->host);
        self::assertSame('pgsql:host=db;port=5432;dbname=fulfillment;connect_timeout=2', $config->db->pdoDsn());
    }

    #[Test]
    public function environmentOverridesDefaults(): void
    {
        putenv('FULFILLMENT_HTTP_PORT=9999');
        putenv('FULFILLMENT_DB_HOST=elsewhere');

        $config = FulfillmentConfig::fromEnv();

        self::assertSame(9999, $config->http->port);
        self::assertSame('elsewhere', $config->db->host);
    }
}
