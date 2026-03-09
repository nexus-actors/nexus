<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Fixture;

interface ProductServiceInterface
{
    public function getProduct(string $id): Product;

    public function createProduct(string $name, float $price): Product;

    public function deleteProduct(string $id): void;
}
