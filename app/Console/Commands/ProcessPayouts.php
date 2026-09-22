<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessInstructorPayout;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds instructors with a payable balance and queues one job each.
 *
 * Running it twice is harmless. The second run either finds the balance already
 * claimed by an open payout and skips it, or — if it races the first — is stopped
 * by the unique index when the job tries to open a second payout.
 *
 * Scale: instructors are walked with chunkById over the projection table, so the
 * command's memory use is constant whether there are twelve instructors or a
 * hundred thousand. It never touches the ledger.
 */
class ProcessPayouts extends Command
{
    protected $signature = 'payouts:process
                            {--instructor= : Pay a single instructor by id}
                            {--min-amount= : Minimum payable balance in MINOR units}
                            {--limit=0 : Stop after queueing this many payouts (0 = no limit)}
                            {--dry-run : Report what would be queued, and change nothing}';

    protected $description = 'Queue payouts for instructors with a payable balance';

    public function handle(): int
    {
        $minimum = max(1, (int) ($this->option('min-amount') ?? config('revenue.payouts.minimum_minor')));
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $queued = 0;
        $totalMinor = 0;
        $rows = [];

        // Measured before anything is queued: otherwise a synchronous run
        // counts the payouts it has just opened as having blocked it.
        $skipped = $this->instructorsBlockedByAnOpenPayout($minimum);

        $this->payableInstructors($minimum)->chunkById(500, function ($balances) use (
            &$queued, &$totalMinor, &$rows, $limit, $dryRun, $minimum
        ) {
            foreach ($balances as $balance) {
                if ($dryRun) {
                    $rows[] = [$balance->instructor_id, Money::format((int) $balance->outstanding_minor)];
                } else {
                    ProcessInstructorPayout::dispatch((int) $balance->instructor_id, $minimum);
                }

                $queued++;
                $totalMinor += (int) $balance->outstanding_minor;

                if ($limit > 0 && $queued >= $limit) {
                    return false;
                }
            }
        }, 'instructor_id');

        if ($dryRun) {
            $this->table(['Instructor', 'Outstanding'], $rows);
            $this->info(sprintf('Dry run: %d payout(s) totalling %s would be queued.', $queued, Money::format($totalMinor)));

            return self::SUCCESS;
        }

        $this->info(sprintf('Queued %d payout job(s) covering up to %s.', $queued, Money::format($totalMinor)));

        if ($skipped > 0) {
            $this->line("  {$skipped} instructor(s) skipped: a payout was already open for them.");
        }

        return self::SUCCESS;
    }

    /**
     * Payable means: enough outstanding, and no payout already in flight.
     *
     * The open-payout exclusion is an optimisation that avoids queueing jobs
     * destined to do nothing. It is not what prevents double payment — the unique
     * index on payouts.open_instructor_id is.
     */
    private function payableInstructors(int $minimum): Builder
    {
        $query = DB::table('instructor_balances')
            ->select(['instructor_id', 'outstanding_minor'])
            ->where('outstanding_minor', '>=', $minimum)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('payouts')
                    ->whereColumn('payouts.instructor_id', 'instructor_balances.instructor_id')
                    ->whereIn('payouts.status', ['pending', 'processing', 'unknown']);
            });

        if ($instructorId = $this->option('instructor')) {
            $query->where('instructor_id', (int) $instructorId);
        }

        return $query;
    }

    /**
     * Instructors who would otherwise be paid, but already have a payout in
     * flight. Reported so an operator can tell "nothing to do" apart from
     * "something is stuck".
     */
    private function instructorsBlockedByAnOpenPayout(int $minimum): int
    {
        $query = DB::table('instructor_balances')
            ->where('outstanding_minor', '>=', $minimum)
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('payouts')
                    ->whereColumn('payouts.instructor_id', 'instructor_balances.instructor_id')
                    ->whereIn('payouts.status', ['pending', 'processing', 'unknown']);
            });

        if ($instructorId = $this->option('instructor')) {
            $query->where('instructor_id', (int) $instructorId);
        }

        return $query->count();
    }
}
