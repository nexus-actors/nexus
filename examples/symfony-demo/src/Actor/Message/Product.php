<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class Product
{
    public function __construct(
        public string $description,
        public string $id,
        public string $name,
        public float $price,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'id'          => $this->id,
            'name'        => $this->name,
            'price'       => $this->price,
        ];
    }
}
