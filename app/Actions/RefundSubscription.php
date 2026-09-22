<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\RefundException;
use App\Models\Refund;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Services\Ledger;
use App\Support\LedgerEntryDraft;
use App\Support\MoneyAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Refunds a subscription and claws the money back from whoever received it.
 *
 * ── The business rules this implements ───────────────────────────────────────
 *
 * WHEN REVENUE IS EARNED
 *   At payment. Students pay the whole term upfront, so the instructor's share is
 *   earned immediately and is payable on the next run. No daily accrual job.
 *
 * WHAT A REFUND RETURNS
 *   The UNUSED portion of the term, pro-rated by whole days. A student one month
 *   into an annual plan gets eleven months back, not twelve. A caller may also
 *   specify an exact amount for a negotiated or partial refund.
 *
 * WHO GIVES THE MONEY BACK
 *   Everyone who received it, in the original proportions — the platform gives up
 *   its commission on the refunded portion too. The split is recomputed from the
 *   weights and fee SNAPSHOTTED in revenue_allocations, so a refund years later
 *   mirrors the original allocation exactly.
 *
 * IF THE INSTRUCTOR WAS ALREADY PAID
 *   Nothing historical is touched. Negative entries are appended, the balance may
 *   go negative, and that debt is netted against future earnings. No payout runs
 *   until the balance climbs back above the minimum.
 *
 * ── Why the arithmetic is written this way ───────────────────────────────────
 *
 * Each refund does NOT allocate its own amount independently. Allocating
 * 30 + 30 + 40 separately can claw back a minor unit more (or less) than
 * allocating 100 once, because each split rounds on its own.
 *
 * Instead the action computes the clawback that SHOULD exist in total for
 * everything refunded so far, subtracts what has already been posted, and writes
 * the difference. Any sequence of partial refunds therefore lands on exactly the
 * same totals as one refund of the same size — and the cumulative clawback can
 * never exceed the original allocation.
 */
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

    /**
     * The unused value of the term, in whole days.
     */
    public function proratedAmount(Subscription $subscription, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        $termDays = $subscription->termDays();
        $remainingDays = (int) $asOf->diffInDays($subscription->ends_at, false);
        $remainingDays = max(0, min($remainingDays, $termDays));

        // intdiv, never a float division: a pro-rata refund is money.
        return intdiv($subscription->gross_amount_minor * $remainingDays, $termDays);
    }

    /**
     * The difference between the clawback that should exist and the one that does.
     *
     * @param  Collection<int, RevenueAllocation>  $allocations
     * @return array<int, LedgerEntryDraft>
     */
    private function clawbackEntries(
        Subscription $subscription,
        $allocations,
        Refund $refund,
        int $cumulativeRefunded,
    ): array {
        // The same weights and the same fee that produced the original split.
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

    /**
     * How much has already been clawed back for this subscription, per account.
     *
     * Read from the ledger itself rather than from a running total, so it is
     * correct even if entries were written by a different process.
     *
     * @return array{instructors: array<int, int>, platform: int}
     */
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
            // Clawbacks are stored negative; the target is expressed positive.
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
