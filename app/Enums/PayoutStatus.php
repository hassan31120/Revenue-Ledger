<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The payout lifecycle.
 *
 *                      ┌──────────┐
 *                      │ pending  │  row created, nothing sent yet
 *                      └────┬─────┘
 *             claimed by a worker (conditional UPDATE)
 *                      ┌────▼─────┐
 *                      │processing│  provider call in flight
 *                      └──┬──┬──┬─┘
 *         definite success│  │  │ definite refusal
 *                      ┌──▼─┐│┌─▼─────┐
 *                      │paid│││failed │
 *                      └────┘│└───────┘
 *                            │ timeout / crash / ambiguity
 *                       ┌────▼────┐
 *                       │ unknown │  the money may or may not have moved
 *                       └──┬───┬──┘
 *          provider says   │   │  provider has no record
 *          it settled      │   │  after the grace period
 *                     ┌────▼┐  └──►┌───────┐
 *                     │paid │      │failed │
 *                     └─────┘      └───────┘
 *
 * The distinction that matters is `failed` versus `unknown`:
 *
 *   failed   the provider told us no money moved. The balance is payable again.
 *   unknown  we do not know. The instructor stays frozen until reconciliation
 *            settles it. The system never guesses, and never treats silence
 *            as a negative answer.
 *
 * `paid` is absorbing. It is defended three times over: this state machine
 * rejects the transition, the ledger debit is protected by a unique idempotency
 * key, and the generated-column unique index stops a fresh payout being opened
 * while the balance is already zero.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /**
     * Terminal states release the instructor's single "open payout" slot.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed], true);
    }

    /**
     * Open states occupy the slot, so no second payout can be created.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // A pending payout has not been sent: the claim moves it to processing.
            // It may also be abandoned if a worker dies before claiming it.
            self::Pending => [self::Processing, self::Unknown, self::Failed],

            // The only state from which a provider answer is expected.
            self::Processing => [self::Paid, self::Failed, self::Unknown],

            // Reconciliation resolves it, or leaves it unknown and tries again.
            self::Unknown => [self::Paid, self::Failed, self::Unknown],

            // Absorbing. A paid payout never becomes payable again.
            self::Paid => [],

            // Terminal. The earnings return to the balance and a NEW payout is
            // created for them; this row is never revived.
            self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown — needs reconciliation',
        };
    }

    /**
     * Colour used by the read-only Filament screen.
     *
     * `unknown` is deliberately a warning rather than a danger colour: it is not
     * a failure, and an operator must not read it as one.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
        };
    }
}
