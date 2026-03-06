<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $customerId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string')]
    private string $productId;

    #[ORM\Column(type: 'integer')]
    private int $qty;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    public function __construct(Ulid $id, string $customerId, string $productId, int $qty)
    {
        $this->id         = $id->toBase32();
        $this->customerId = $customerId;
        $this->createdAt  = new DateTimeImmutable();
        $this->productId  = $productId;
        $this->qty        = $qty;
        $this->status     = 'accepted';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }
}
