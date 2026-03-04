<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use RuntimeException;

final class CoroutineScope
{
    private const string CONTEXT_KEY = '__nexus_scope__';

    public function __construct(private readonly CoroutineContext $context) {}

    /**
     * @param array<string, callable(): object> $factories
     */
    public function initialize(array $factories): void
    {
        $ctx       = $this->context->current();
        $instances = [];

        foreach ($factories as $id => $factory) {
            $instances[$id] = $factory();
        }

        $ctx[self::CONTEXT_KEY] = $instances;
    }

    public function get(string $id): object
    {
        $ctx = $this->context->current();

        /** @var array<string, object>|null $instances */
        $instances = $ctx[self::CONTEXT_KEY] ?? null;

        if ($instances === null || !array_key_exists($id, $instances)) {
            throw new RuntimeException(sprintf('Service "%s" not in coroutine scope.', $id));
        }

        return $instances[$id];
    }
}
