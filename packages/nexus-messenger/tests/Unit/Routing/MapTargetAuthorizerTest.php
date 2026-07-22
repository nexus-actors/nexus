<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Messenger\Routing\MapTargetAuthorizer;
use Monadial\Nexus\Messenger\Stamp\ProducerIdentityStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(MapTargetAuthorizer::class)]
final class MapTargetAuthorizerTest extends TestCase
{
    #[Test]
    public function allowsAConfiguredIdentityToReachAConfiguredTarget(): void
    {
        $authorizer = new MapTargetAuthorizer(['orders-svc' => ['/user/orders', '/user/audit']]);

        self::assertTrue($authorizer->authorize('/user/orders', $this->envelopeFrom('orders-svc')));
        self::assertTrue($authorizer->authorize('/user/audit', $this->envelopeFrom('orders-svc')));
    }

    #[Test]
    public function deniesATargetOutsideTheIdentitysAllowlist(): void
    {
        $authorizer = new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]);

        self::assertFalse($authorizer->authorize('/user/payments', $this->envelopeFrom('orders-svc')));
    }

    #[Test]
    public function deniesAnIdentityThatIsNotInTheAcl(): void
    {
        $authorizer = new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]);

        self::assertFalse($authorizer->authorize('/user/orders', $this->envelopeFrom('rogue-svc')));
    }

    #[Test]
    public function deniesAnEnvelopeWithNoProducerIdentityStamp(): void
    {
        $authorizer = new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]);
        $message = new stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/orders')]);

        self::assertFalse($authorizer->authorize('/user/orders', $envelope));
    }

    private function envelopeFrom(string $identity): Envelope
    {
        $message = new stdClass();

        return new Envelope($message, [
            new TargetActorPathStamp('/user/orders'),
            new ProducerIdentityStamp($identity),
        ]);
    }
}
