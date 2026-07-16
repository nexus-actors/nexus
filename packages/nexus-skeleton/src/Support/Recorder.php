<?php

declare(strict_types=1);

namespace App\Support;

/**
 * @psalm-api sample fixture of the skeleton template — GreeterActor writes
 * $greeted; user code (and the template's KernelBootTest) reads it back
 */
final class Recorder
{
    /** @var list<string> */
    public array $greeted = [];
}
