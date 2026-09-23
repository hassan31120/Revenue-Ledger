<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Exceptions\InvalidPayoutTransitionException;
use App\Models\Payout;
use App\Support\LedgerEntryDraft;
use Illuminate\Support\Facades\DB;

final class PayoutRecorder
{
    public function __construct(private readonly Ledger $ledger) {}

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

    public function markFailed(Payout $payout, string $error): void
    {
        $this->transition($payout, PayoutStatus::Failed, ['last_error' => $error]);
    }

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

    private function transition(Payout $payout, PayoutStatus $to, array $attributes = []): void
    {
        $from = $payout->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidPayoutTransitionException::between($payout->id, $from, $to);
        }

        Payout::query()
            ->whereKey($payout->id)
            ->where('status', $from->value)
            ->update([...$attributes, 'status' => $to->value, 'updated_at' => now()]);

        $payout->status = $to;
    }
}
