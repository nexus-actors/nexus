<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Handler;

/**
 * @psalm-api
 *
 * Marker for command handlers. Implementers declare ONE of:
 *
 *   public function __invoke(ConcreteCommand $command): void
 *   public function __invoke(ConcreteCommand $command, MessageContext $ctx): void
 *
 * The second parameter is optional — declare it only if the handler
 * actually needs to read metadata. The `nexus-psalm` plugin's
 * `CommandHandlerSignatureRule` validates the shape.
 */
interface CommandHandler {}
