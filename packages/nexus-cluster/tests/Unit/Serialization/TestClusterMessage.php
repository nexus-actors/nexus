<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Serialization;

final readonly class TestClusterMessage
{
    public function __construct(public string $text, public int $value) {}
}
