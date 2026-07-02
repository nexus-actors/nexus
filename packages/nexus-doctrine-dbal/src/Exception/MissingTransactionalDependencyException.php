<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class MissingTransactionalDependencyException extends NexusException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function onHandler(string $handlerClass): self
    {
        return new self(sprintf(
            '#[Transactional] requires the handler "%s" to declare a Connection (or EntityManagerInterface) parameter.',
            $handlerClass,
        ));
    }
}
