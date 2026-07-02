<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Tests\Unit;

use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\State\WalletState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WalletStateTest extends TestCase
{
    #[Test]
    public function emptyStateIsClosedWithZeroBalance(): void
    {
        $state = WalletState::empty();

        self::assertFalse($state->opened);
        self::assertSame(0, $state->balance->cents);
    }

    #[Test]
    public function openMarksWalletOpenedKeepingBalanceUntouched(): void
    {
        $state = WalletState::empty()->open();

        self::assertTrue($state->opened);
        self::assertSame(0, $state->balance->cents);
    }

    #[Test]
    public function depositAccumulates(): void
    {
        $state = WalletState::empty()
            ->open()
            ->deposited(new Money(500))
            ->deposited(new Money(750));

        self::assertSame(1250, $state->balance->cents);
    }

    #[Test]
    public function withdrawDeducts(): void
    {
        $state = WalletState::empty()
            ->open()
            ->deposited(new Money(1000))
            ->withdrew(new Money(300));

        self::assertSame(700, $state->balance->cents);
    }

    #[Test]
    public function stateIsImmutable(): void
    {
        // Folding events must never mutate — that's the contract the
        // event-sourced engine relies on for replay safety.
        $original = WalletState::empty()->open()->deposited(new Money(500));
        $derived = $original->deposited(new Money(100));

        self::assertSame(500, $original->balance->cents);
        self::assertSame(600, $derived->balance->cents);
    }
}
