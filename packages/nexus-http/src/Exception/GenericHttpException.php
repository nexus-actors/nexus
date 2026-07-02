<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

/**
 * @psalm-api
 *
 * Concrete carrier used by {@see HttpException} factory methods.
 * HttpException itself is abstract per project coding standards;
 * this class exists so factories can instantiate a concrete value.
 */
final class GenericHttpException extends HttpException {}
