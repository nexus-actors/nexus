<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Exception;

use RuntimeException;

/**
 * Base class for all tic-tac-toe domain errors.
 *
 * Every subclass is a rule violation the client can act on — HTTP handlers
 * map these to 4xx responses.
 */
abstract class GameDomainException extends RuntimeException {}
