<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\OrderController;
use App\Service\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(OrderController::class)]
final class OrderControllerTest extends TestCase
{
    #[Test]
    public function place_dispatchesToBusAndReturns202(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $request = Request::create(
            '/orders',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'customerId' => 'cust-1',
                'productId'  => 'chair-001',
                'qty'        => 2,
            ]),
        );

        $controller = new OrderController($bus);
        $response   = $controller->place($request, new RequestContext());

        self::assertSame(202, $response->getStatusCode());
        /** @var array{status: string, requestId: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('queued', $data['status']);
        self::assertArrayHasKey('requestId', $data);
    }
}
