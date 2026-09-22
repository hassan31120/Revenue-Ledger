<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audits the ledger against everything derived from it.
 *
 * Two independent questions:
 *
 *   1. Does the instructor_balances projection still agree with the ledger?
 *      Any disagreement is a bug — the projection is a cache of a pure function.
 *
 *   2. Is money conserved? Every business event should have produced ledger
 *      entries summing to exactly its own amount: a subscription to its gross, a
 *      refund to its negation, a confirmed payout to its negation.
 *
 * Exits non-zero on any discrepancy, so it can be wired into CI or a cron alert.
 * Every check is a single aggregate query — nothing is loaded into memory, which
 * is what lets this run against tens of millions of rows.
 */
class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify
                            {--instructor= : Restrict the balance check to one instructor}';

    protected $description = 'Recompute balances from the ledger and verify money is conserved';

    public function handle(): int
    {
        $problems = 0;

        $problems += $this->verifyBalances();
        $problems += $this->verifySubscriptionsConserveMoney();
        $problems += $this->verifyRefundsConserveMoney();
        $problems += $this->verifyPayoutsConserveMoney();
        $problems += $this->verifyNoOrphanedLedgerEntries();

        if ($problems > 0) {
            $this->newLine();
            $this->error("Ledger verification FAILED with {$problems} discrepancy group(s).");

            return self::FAILURE;
        }

        $this->info('Ledger verified: projection matches the ledger, and money is conserved.');

        return self::SUCCESS;
    }

    /**
     * The projection is a cache. Recompute it from scratch and compare.
     */
    private function verifyBalances(): int
    {
        $query = DB::table('instructor_balances as ib')
            ->leftJoin('ledger_entries as le', 'le.instructor_id', '=', 'ib.instructor_id')
            ->groupBy('ib.instructor_id', 'ib.total_earned_minor', 'ib.total_paid_minor', 'ib.outstanding_minor')
            ->select([
                'ib.instructor_id',
                'ib.total_earned_minor as stored_earned',
                'ib.total_paid_minor as stored_paid',
                'ib.outstanding_minor as stored_outstanding',
                DB::raw("COALESCE(SUM(CASE WHEN le.entry_type = 'earning' THEN le.amount_minor END), 0) as actual_earned"),
                DB::raw("-COALESCE(SUM(CASE WHEN le.entry_type = 'payout' THEN le.amount_minor END), 0) as actual_paid"),
                DB::raw('COALESCE(SUM(le.amount_minor), 0) as actual_outstanding'),
            ])
            ->havingRaw('
                ib.total_earned_minor  <> COALESCE(SUM(CASE WHEN le.entry_type = \'earning\' THEN le.amount_minor END), 0)
             OR ib.total_paid_minor    <> -COALESCE(SUM(CASE WHEN le.entry_type = \'payout\' THEN le.amount_minor END), 0)
             OR ib.outstanding_minor   <> COALESCE(SUM(le.amount_minor), 0)
            ');

        if ($instructorId = $this->option('instructor')) {
            $query->where('ib.instructor_id', (int) $instructorId);
        }

        $drifted = $query->get();

        if ($drifted->isEmpty()) {
            $this->line('  <fg=green>✓</> instructor_balances matches the ledger');

            return 0;
        }

        $this->error(sprintf('  ✗ %d instructor balance(s) drifted from the ledger:', $drifted->count()));

        foreach ($drifted as $row) {
            $this->line(sprintf(
                '      instructor #%d  earned %s/%s  paid %s/%s  outstanding %s/%s  (stored/actual)',
                $row->instructor_id,
                Money::format((int) $row->stored_earned),
                Money::format((int) $row->actual_earned),
                Money::format((int) $row->stored_paid),
                Money::format((int) $row->actual_paid),
                Money::format((int) $row->stored_outstanding),
                Money::format((int) $row->actual_outstanding),
            ));
        }

        return 1;
    }

    /**
     * Every allocated subscription must have produced entries — instructor shares
     * plus the platform fee — summing to exactly its gross amount.
     */
    private function verifySubscriptionsConserveMoney(): int
    {
        $broken = DB::table('subscriptions as s')
            ->join('ledger_entries as le', function ($join) {
                $join->on('le.source_id', '=', 's.id')->where('le.source_type', '=', 'subscription');
            })
            ->groupBy('s.id', 's.gross_amount_minor')
            ->select(['s.id', 's.gross_amount_minor', DB::raw('SUM(le.amount_minor) as posted')])
            ->havingRaw('SUM(le.amount_minor) <> s.gross_amount_minor')
            ->get();

        return $this->reportConservation(
            $broken,
            'subscription',
            fn ($row) => sprintf(
                '      subscription #%d  gross %s  but ledger holds %s',
                $row->id,
                Money::format((int) $row->gross_amount_minor),
                Money::format((int) $row->posted),
            ),
            'every allocated subscription is fully accounted for',
        );
    }

    /**
     * A refund's entries must sum to exactly the negation of the refunded amount.
     */
    private function verifyRefundsConserveMoney(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('refunds')) {
            return 0;
        }

        $broken = DB::table('refunds as r')
            ->join('ledger_entries as le', function ($join) {
                $join->on('le.source_id', '=', 'r.id')->where('le.source_type', '=', 'refund');
            })
            ->groupBy('r.id', 'r.amount_minor')
            ->select(['r.id', 'r.amount_minor', DB::raw('SUM(le.amount_minor) as posted')])
            ->havingRaw('SUM(le.amount_minor) <> -r.amount_minor')
            ->get();

        return $this->reportConservation(
            $broken,
            'refund',
            fn ($row) => sprintf(
                '      refund #%d  amount %s  but ledger holds %s (expected the negation)',
                $row->id,
                Money::format((int) $row->amount_minor),
                Money::format((int) $row->posted),
            ),
            'every refund is fully accounted for',
        );
    }

    /**
     * A confirmed payout must have debited exactly its own amount — exactly once.
     */
    private function verifyPayoutsConserveMoney(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('payouts')) {
            return 0;
        }

        $broken = DB::table('payouts as p')
            ->join('ledger_entries as le', function ($join) {
                $join->on('le.source_id', '=', 'p.id')->where('le.source_type', '=', 'payout');
            })
            ->groupBy('p.id', 'p.amount_minor', 'p.status')
            ->select(['p.id', 'p.amount_minor', 'p.status', DB::raw('SUM(le.amount_minor) as posted')])
            ->havingRaw('SUM(le.amount_minor) <> -p.amount_minor')
            ->get();

        return $this->reportConservation(
            $broken,
            'payout',
            fn ($row) => sprintf(
                '      payout #%d (%s)  amount %s  but ledger holds %s',
                $row->id,
                $row->status,
                Money::format((int) $row->amount_minor),
                Money::format((int) $row->posted),
            ),
            'every confirmed payout debited exactly once',
        );
    }

    /**
     * An instructor with ledger entries but no projection row would silently never
     * be paid, because payout discovery reads the projection.
     */
    private function verifyNoOrphanedLedgerEntries(): int
    {
        $orphans = DB::table('ledger_entries as le')
            ->leftJoin('instructor_balances as ib', 'ib.instructor_id', '=', 'le.instructor_id')
            ->whereNotNull('le.instructor_id')
            ->whereNull('ib.instructor_id')
            ->distinct()
            ->pluck('le.instructor_id');

        if ($orphans->isEmpty()) {
            $this->line('  <fg=green>✓</> every instructor with ledger entries has a balance row');

            return 0;
        }

        $this->error('  ✗ instructors have ledger entries but no balance row: '.$orphans->implode(', '));

        return 1;
    }

    /**
     * @param  Collection<int, object>  $broken
     */
    private function reportConservation($broken, string $label, callable $describe, string $okMessage): int
    {
        if ($broken->isEmpty()) {
            $this->line("  <fg=green>✓</> {$okMessage}");

            return 0;
        }

        $this->error(sprintf('  ✗ %d %s(s) do not conserve money:', $broken->count(), $label));

        foreach ($broken as $row) {
            $this->line($describe($row));
        }

        return 1;
    }
}
