<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use RuntimeException;

/**
 * Thrown at boot when WALLET_ENV=production but the app is still configured
 * with the built-in demo secrets/defaults (default DB password, demo auth
 * tokens). Fails closed so a copied deployment cannot ship insecure demo
 * credentials to production.
 */
final class DemoDefaultsInProductionException extends RuntimeException
{
    public function __construct(string $what)
    {
        parent::__construct(
            "Refusing to boot in production: {$what} still uses the built-in demo default. "
            . 'Set a real value (WALLET_DB_PASS, WALLET_AUTH_TOKENS, WALLET_ADMIN_TOKENS) '
            . 'or run with WALLET_ENV=dev for local development.',
        );
    }
}
