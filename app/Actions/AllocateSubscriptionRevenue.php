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

final class AllocateSubscriptionRevenue
{
    public function __construct(private readonly Ledger $ledger) {}

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

        DB::table('revenue_allocations')->insertOrIgnore($rows);
    }

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
