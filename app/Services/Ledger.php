<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class Ledger
{
    public function post(array $drafts): int
    {
        if ($drafts === []) {
            return 0;
        }

        return DB::transaction(function () use ($drafts) {
            $written = [];
            $lastInsertId = 0;

            foreach ($drafts as $draft) {
                try {
                    $id = DB::table('ledger_entries')->insertGetId($draft->toRow());

                    $written[] = $draft;
                    $lastInsertId = max($lastInsertId, $id);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            if ($written !== []) {
                $this->applyToProjection($written, $lastInsertId);
            }

            return count($written);
        });
    }

    private function applyToProjection(array $written, int $lastInsertId): void
    {
        $deltas = [];

        foreach ($written as $draft) {
            if ($draft->accountType !== AccountType::Instructor) {
                continue;
            }

            $id = $draft->instructorId;
            $deltas[$id] ??= ['earned' => 0, 'paid' => 0, 'outstanding' => 0];

            $deltas[$id]['outstanding'] += $draft->amountMinor;

            if ($draft->entryType === LedgerEntryType::Earning) {
                $deltas[$id]['earned'] += $draft->amountMinor;
            }

            if ($draft->entryType === LedgerEntryType::Payout) {
                $deltas[$id]['paid'] -= $draft->amountMinor;
            }
        }

        foreach ($deltas as $instructorId => $delta) {
            DB::table('instructor_balances')->insertOrIgnore([
                'instructor_id' => $instructorId,
                'total_earned_minor' => 0,
                'total_paid_minor' => 0,
                'outstanding_minor' => 0,
                'last_ledger_entry_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->update([
                    'total_earned_minor' => DB::raw("total_earned_minor + {$delta['earned']}"),
                    'total_paid_minor' => DB::raw("total_paid_minor + {$delta['paid']}"),
                    'outstanding_minor' => DB::raw("outstanding_minor + {$delta['outstanding']}"),
                    'last_ledger_entry_id' => DB::raw("GREATEST(last_ledger_entry_id, {$lastInsertId})"),
                    'updated_at' => now(),
                ]);
        }
    }
}
