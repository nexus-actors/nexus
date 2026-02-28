<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\NodeHashRing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeHashRing::class)]
final class NodeHashRingTest extends TestCase
{
    #[Test]
    public function sameNameAlwaysReturnsSameNode(): void
    {
        $nodes = $this->makeNodes(4);
        $ring = new NodeHashRing($nodes);

        $first = $ring->getNode('orders');
        $second = $ring->getNode('orders');

        self::assertSame($first->node, $second->node);
    }

    #[Test]
    public function returnedNodeIsOneOfTheProvided(): void
    {
        $nodes = $this->makeNodes(4);
        $ring = new NodeHashRing($nodes);

        for ($i = 0; $i < 100; $i++) {
            $result = $ring->getNode("actor-{$i}");
            $found = false;

            foreach ($nodes as $node) {
                if ($node->node === $result->node) {
                    $found = true;

                    break;
                }
            }

            self::assertTrue($found, "Returned node not in provided list: {$result->node}");
        }
    }

    #[Test]
    public function allNodesAreReachable(): void
    {
        $nodes = $this->makeNodes(4);
        $ring = new NodeHashRing($nodes);
        $seen = [];

        for ($i = 0; $i < 1000; $i++) {
            $seen[$ring->getNode("test-actor-{$i}")->node] = true;
        }

        self::assertCount(4, $seen, 'All 4 nodes should be reachable');
    }

    #[Test]
    public function singleNodeAlwaysReturnsItself(): void
    {
        $nodes = $this->makeNodes(1);
        $ring = new NodeHashRing($nodes);

        self::assertSame($nodes[0]->node, $ring->getNode('anything')->node);
        self::assertSame($nodes[0]->node, $ring->getNode('something-else')->node);
    }

    #[Test]
    public function twoRingsWithSameNodesAgreeOnPlacement(): void
    {
        $nodes = $this->makeNodes(8);
        $ring1 = new NodeHashRing($nodes);
        $ring2 = new NodeHashRing($nodes);

        for ($i = 0; $i < 100; $i++) {
            $name = "actor-{$i}";
            self::assertSame(
                $ring1->getNode($name)->node,
                $ring2->getNode($name)->node,
                "Rings should agree on placement for {$name}",
            );
        }
    }

    /** @return list<NodeAddress> */
    private function makeNodes(int $count): array
    {
        $nodes = [];

        for ($i = 0; $i < $count; $i++) {
            $nodes[] = new NodeAddress('local', 'dc1', 'app', "node-{$i}");
        }

        return $nodes;
    }
}
