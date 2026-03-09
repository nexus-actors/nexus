<?php

declare(strict_types=1);

namespace App\Controller;

use App\Doctrine\SwoolePDOPool;
use App\Message\PlaceOrder;
use App\Service\RequestContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

final class OrderController extends AbstractController
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    #[Route('/orders', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $pool = SwoolePDOPool::current();
        assert($pool !== null, 'DB pool not initialized');

        $pdo = $pool->get();

        try {
            /** @var \PDOStatement $stmt */
            $stmt = $pdo->query('SELECT id, status FROM orders ORDER BY created_at DESC LIMIT 10');
            /** @var list<array{id: string, status: string}> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } finally {
            $pool->put($pdo);
        }

        return new JsonResponse($rows);
    }

    #[Route('/orders/write', methods: ['POST'])]
    public function write(): JsonResponse
    {
        $pool = SwoolePDOPool::current();
        assert($pool !== null, 'DB pool not initialized');

        $id  = (new Ulid())->toBase32();
        $now = date('Y-m-d H:i:s');

        $pdo = $pool->get();

        try {
            /** @var \PDOStatement $stmt */
            $stmt = $pdo->prepare(
                'INSERT INTO orders (id, customer_id, product_id, qty, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([$id, 'bench-customer', 'chair-001', 1, 'accepted', $now]);
        } finally {
            $pool->put($pdo);
        }

        return new JsonResponse(['id' => $id], 201);
    }

    #[Route('/orders', methods: ['POST'])]
    public function place(Request $request, RequestContext $ctx): JsonResponse
    {
        /** @var array{customerId: string, productId: string, qty: int} $body */
        $body = json_decode((string) $request->getContent(), true);

        $this->bus->dispatch(new PlaceOrder($body['customerId'], $body['productId'], $body['qty']));

        return new JsonResponse([
            'requestId' => $ctx->requestId,
            'status'    => 'queued',
        ], 202);
    }
}
