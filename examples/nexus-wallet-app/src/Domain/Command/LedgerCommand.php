<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Command;

/**
 * Marker interface for every command the per-owner `LedgerActor`
 * understands.
 *
 * Use the interface — not a bare `object` — in actor signatures so the
 * type system knows the closed set: a new command must implement this
 * interface to be sendable to the ledger.
 *
 * @psalm-api
 */
interface LedgerCommand {}
