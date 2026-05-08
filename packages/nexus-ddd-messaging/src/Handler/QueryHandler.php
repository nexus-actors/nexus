<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Handler;

/**
 * @psalm-api
 *
 * @template TResult
 *
 * Marker for query handlers. Implementers declare ONE of:
 *
 *   public function __invoke(ConcreteQuery $query): TResult
 *   public function __invoke(ConcreteQuery $query, MessageContext $ctx): TResult
 *
 * Validated by `QueryHandlerSignatureRule`. Return type must match the
 * `TResult` template parameter on `Query<TResult>`.
 */
interface QueryHandler {}
