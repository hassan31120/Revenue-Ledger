<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Exceptions\InvalidPayoutTransitionException;
use App\Models\Payout;
use App\Support\LedgerEntryDraft;
use Illuminate\Support\Facades\DB;

/**
 * The single implementation of "what happens when a payout resolves".
 *
 * Both paths into a terminal state — the first send, and reconciliation after a
 * timeout — go through here. Duplicating this logic would mean two places where
 * the ledger debit might be written under subtly different conditions, and that
 * is the one piece of code in the system that must be written exactly once.
 */
final class PayoutRecorder
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * The payment is confirmed. Recognise it and debit the ledger, atomically.
     *
     * Both halves are idempotent: the status change is conditional on the status
     * we believe the row holds, and the debit is protected by a unique
     * idempotency key derived from the payout id. Two workers both concluding
     * "paid" therefore produce one debit, not two.
     */
    public function markPaid(Payout $payout, string $reference): void
    {
        DB::transaction(function () use ($payout, $reference) {
            if (! $payout->status->isTerminal()) {
                $this->transition($payout, PayoutStatus::Paid, [
                    'provider_reference' => $reference,
                    'completed_at' => now(),
                    'last_error' => null,
                ]);
            }

            $this->ledger->post([
                LedgerEntryDraft::forInstructor(
                    instructorId: $payout->instructor_id,
                    entryType: LedgerEntryType::Payout,
                    amountMinor: -$payout->amount_minor,
                    currency: $payout->currency,
                    sourceType: 'payout',
                    sourceId: $payout->id,
                    idempotencyKey: $payout->ledgerIdempotencyKey(),
                ),
            ]);
        });
    }

    /**
     * The provider stated definitively that no money moved.
     *
     * No ledger entry is written, so the balance remains payable and the next run
     * opens a NEW payout for it. This row is never revived.
     */
    public function markFailed(Payout $payout, string $error): void
    {
        $this->transition($payout, PayoutStatus::Failed, ['last_error' => $error]);
    }

    /**
     * The outcome is unresolved.
     *
     * No ledger entry, and the payout stays open — which keeps the instructor's
     * single open-payout slot occupied, so nothing can pay them again while the
     * question is outstanding. Staying stuck here is the correct behaviour;
     * guessing is the bug.
     */
    public function markUnknown(Payout $payout, string $error, ?int $delaySeconds = null): void
    {
        $delaySeconds ??= (int) config('revenue.payouts.reconcile_delay_seconds');

        $this->transition($payout, PayoutStatus::Unknown, [
            'last_error' => $error,
            'reconcile_after' => now()->addSeconds($delaySeconds),
        ]);
    }

    public function recordAttempt(
        Payout $payout,
        string $kind,
        string $outcome,
        ?string $reference = null,
        ?string $detail = null,
    ): void {
        DB::table('payout_attempts')->insert([
            'payout_id' => $payout->id,
            'attempt_no' => $payout->attempts,
            'kind' => $kind,
            'outcome' => $outcome,
            'idempotency_key_sent' => $payout->provider_idempotency_key,
            'provider_reference' => $reference,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Payout $payout, PayoutStatus $to, array $attributes = []): void
    {
        $from = $payout->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidPayoutTransitionException::between($payout->id, $from, $to);
        }

        // Conditional on the status this process believes the row holds, so a
        // concurrent change is never clobbered.
        Payout::query()
            ->whereKey($payout->id)
            ->where('status', $from->value)
            ->update([...$attributes, 'status' => $to->value, 'updated_at' => now()]);

        $payout->status = $to;
    }
}
