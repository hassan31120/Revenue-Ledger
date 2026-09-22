<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LedgerEntryType;
use App\Exceptions\RevenueAllocationException;
use App\Models\Subscription;
use App\Services\Ledger;
use App\Support\LedgerEntryDraft;
use App\Support\MoneyAllocator;
use App\Support\RevenueSplit;
use Illuminate\Support\Facades\DB;

/**
 * Turns one subscription payment into money that belongs to specific accounts.
 *
 * Runs exactly once per subscription in effect, however many times it is called.
 * That is guaranteed by two database constraints rather than by an application
 * check: the unique index on (subscription_id, instructor_id), and the unique
 * index on ledger_entries.idempotency_key. Two workers racing on the same
 * subscription therefore produce one allocation, not two.
 */
final class AllocateSubscriptionRevenue
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return RevenueSplit the split that applies to this subscription — the same
     *                      value on every call, whether or not this call wrote it
     */
    public function handle(Subscription $subscription): RevenueSplit
    {
        $weights = $this->resolveWeights($subscription);

        $split = MoneyAllocator::split(
            grossMinor: $subscription->gross_amount_minor,
            platformFeeBps: $subscription->platform_fee_bps,
            weights: $weights,
        );

        DB::transaction(function () use ($subscription, $split, $weights) {
            $this->recordAllocations($subscription, $split, $weights);
            $this->ledger->post($this->entriesFor($subscription, $split));
        });

        return $split;
    }

    /**
     * Who shares this subscription, and in what proportion.
     *
     * Resolved from the courses captured at purchase time, never from today's
     * catalog — so reassigning a course later cannot rewrite who earned what.
     *
     * Every participating instructor currently weighs 1: an equal split. The
     * ordering is by instructor id, which is what makes the allocator's tie-break
     * reproducible across machines.
     *
     * @return array<int, int>
     */
    private function resolveWeights(Subscription $subscription): array
    {
        $instructorIds = DB::table('subscription_courses as sc')
            ->join('courses as c', 'c.id', '=', 'sc.course_id')
            ->where('sc.subscription_id', $subscription->id)
            ->distinct()
            ->orderBy('c.instructor_id')
            ->pluck('c.instructor_id');

        if ($instructorIds->isEmpty()) {
            throw RevenueAllocationException::noParticipatingInstructors($subscription->id);
        }

        return $instructorIds->mapWithKeys(fn (int $id) => [$id => 1])->all();
    }

    /**
     * @param  array<int, int>  $weights
     */
    private function recordAllocations(Subscription $subscription, RevenueSplit $split, array $weights): void
    {
        $rows = [];

        foreach ($split->instructorMinor as $instructorId => $amountMinor) {
            $rows[] = [
                'subscription_id' => $subscription->id,
                'instructor_id' => $instructorId,
                'weight' => $weights[$instructorId],
                'amount_minor' => $amountMinor,
                'platform_fee_bps' => $subscription->platform_fee_bps,
                'created_at' => now(),
            ];
        }

        // insertOrIgnore, not insert: a re-run collides with the unique index and
        // is discarded by the database. The allocator is deterministic, so the row
        // already there is identical to the one being discarded.
        DB::table('revenue_allocations')->insertOrIgnore($rows);
    }

    /**
     * One credit per instructor, plus one for the platform.
     *
     * The platform fee is a real ledger entry rather than an implied remainder,
     * which is what lets `ledger:verify` assert that a subscription's entries sum
     * to exactly its gross amount.
     *
     * @return array<int, LedgerEntryDraft>
     */
    private function entriesFor(Subscription $subscription, RevenueSplit $split): array
    {
        $drafts = [];

        foreach ($split->instructorMinor as $instructorId => $amountMinor) {
            $drafts[] = LedgerEntryDraft::forInstructor(
                instructorId: $instructorId,
                entryType: LedgerEntryType::Earning,
                amountMinor: $amountMinor,
                currency: $subscription->currency,
                sourceType: 'subscription',
                sourceId: $subscription->id,
                idempotencyKey: "earning:sub:{$subscription->id}:ins:{$instructorId}",
            );
        }

        $drafts[] = LedgerEntryDraft::forPlatform(
            entryType: LedgerEntryType::PlatformFee,
            amountMinor: $split->platformMinor,
            currency: $subscription->currency,
            sourceType: 'subscription',
            sourceId: $subscription->id,
            idempotencyKey: "platform_fee:sub:{$subscription->id}",
        );

        return $drafts;
    }
}
