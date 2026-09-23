<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\RefundException;
use App\Models\Refund;
use App\Models\Subscription;
use App\Services\Ledger;
use App\Support\LedgerEntryDraft;
use App\Support\MoneyAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RefundSubscription
{
    public function __construct(private readonly Ledger $ledger) {}

    public function handle(
        Subscription $subscription,
        string $providerRefundReference,
        ?int $amountMinor = null,
        string $reason = 'Customer refund',
        ?Carbon $asOf = null,
    ): Refund {
        $allocations = $subscription->revenueAllocations()->orderBy('instructor_id')->get();

        if ($allocations->isEmpty()) {
            throw RefundException::notAllocated($subscription->id);
        }

        $amountMinor ??= $this->proratedAmount($subscription, $asOf ?? now());

        if ($amountMinor <= 0) {
            throw RefundException::notPositive($amountMinor);
        }

        return DB::transaction(function () use ($subscription, $allocations, $amountMinor, $reason, $providerRefundReference, $asOf) {
            $alreadyRefunded = (int) $subscription->refunds()->sum('amount_minor');
            $cumulative = $alreadyRefunded + $amountMinor;

            if ($cumulative > $subscription->gross_amount_minor) {
                throw RefundException::exceedsGross($subscription->id, $cumulative, $subscription->gross_amount_minor);
            }

            $refund = Refund::create([
                'subscription_id' => $subscription->id,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'refunded_at' => $asOf ?? now(),
                'provider_refund_reference' => $providerRefundReference,
            ]);

            $this->ledger->post($this->clawbackEntries($subscription, $allocations, $refund, $cumulative));

            $subscription->update([
                'status' => $cumulative >= $subscription->gross_amount_minor
                    ? SubscriptionStatus::Refunded
                    : SubscriptionStatus::PartiallyRefunded,
            ]);

            return $refund;
        });
    }

    public function proratedAmount(Subscription $subscription, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        $termDays = $subscription->termDays();
        $remainingDays = (int) $asOf->diffInDays($subscription->ends_at, false);
        $remainingDays = max(0, min($remainingDays, $termDays));

        return intdiv($subscription->gross_amount_minor * $remainingDays, $termDays);
    }

    private function clawbackEntries(
        Subscription $subscription,
        $allocations,
        Refund $refund,
        int $cumulativeRefunded,
    ): array {
        $weights = $allocations->mapWithKeys(fn ($a) => [$a->instructor_id => $a->weight])->all();

        $target = MoneyAllocator::split(
            grossMinor: $cumulativeRefunded,
            platformFeeBps: $allocations->first()->platform_fee_bps,
            weights: $weights,
        );

        $alreadyPosted = $this->postedClawbacks($subscription->id);

        $drafts = [];

        foreach ($target->instructorMinor as $instructorId => $targetClawback) {
            $delta = $targetClawback - ($alreadyPosted['instructors'][$instructorId] ?? 0);

            if ($delta === 0) {
                continue;
            }

            $drafts[] = LedgerEntryDraft::forInstructor(
                instructorId: $instructorId,
                entryType: LedgerEntryType::RefundAdjustment,
                amountMinor: -$delta,
                currency: $subscription->currency,
                sourceType: 'refund',
                sourceId: $refund->id,
                idempotencyKey: "refund:{$refund->id}:ins:{$instructorId}",
            );
        }

        $platformDelta = $target->platformMinor - $alreadyPosted['platform'];

        if ($platformDelta !== 0) {
            $drafts[] = LedgerEntryDraft::forPlatform(
                entryType: LedgerEntryType::RefundAdjustment,
                amountMinor: -$platformDelta,
                currency: $subscription->currency,
                sourceType: 'refund',
                sourceId: $refund->id,
                idempotencyKey: "refund:{$refund->id}:platform",
            );
        }

        return $drafts;
    }

    private function postedClawbacks(int $subscriptionId): array
    {
        $rows = DB::table('ledger_entries')
            ->where('entry_type', LedgerEntryType::RefundAdjustment->value)
            ->whereIn('source_id', fn ($q) => $q->select('id')->from('refunds')->where('subscription_id', $subscriptionId))
            ->where('source_type', 'refund')
            ->groupBy('account_type', 'instructor_id')
            ->select(['account_type', 'instructor_id', DB::raw('SUM(amount_minor) as total')])
            ->get();

        $instructors = [];
        $platform = 0;

        foreach ($rows as $row) {
            $posted = -(int) $row->total;

            if ($row->account_type === 'platform') {
                $platform = $posted;
            } else {
                $instructors[(int) $row->instructor_id] = $posted;
            }
        }

        return ['instructors' => $instructors, 'platform' => $platform];
    }
}
