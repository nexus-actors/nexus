<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

/**
 * Mutable probe sink. Captured by value into a probe actor's behavior so the
 * shared instance records every message it receives — lets a test assert what
 * the failure-reply target actually got without capturing an array by
 * reference inside the behavior closure.
 */
final class MessageCollector
{
    /** @var list<object> */
    public array $messages = [];

    public function record(object $message): void
    {
        $this->messages[] = $message;
    }
}
