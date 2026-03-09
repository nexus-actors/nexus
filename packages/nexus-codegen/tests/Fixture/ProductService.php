<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Fixture;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;

#[Actorize(async: true, timeout: 5, namespace: 'Monadial\\Nexus\\Codegen\\Tests\\Fixture\\Generated')]
final class ProductService implements ProductServicePort
{
    public function getProduct(string $id): Product
    {
        return new Product($id, 'Test', 9.99);
    }

    #[Mutates]
    public function createProduct(string $name, float $price): Product
    {
        return new Product('new-id', $name, $price);
    }

    #[Mutates]
    public function deleteProduct(string $id): void
    {
        // no-op in fixture
    }
}
