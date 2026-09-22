<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A PROJECTION of the ledger. Not the source of truth.
     *
     * Summing tens of millions of ledger rows on every payout run is not viable,
     * so running totals are maintained here in the same transaction as each ledger
     * write. This table is an index, not an authority:
     *
     *   - `ledger:verify` recomputes every figure from ledger_entries and reports
     *     any disagreement, which is by definition a bug.
     *   - It is also the row locked with SELECT ... FOR UPDATE when claiming a
     *     payout, giving per-instructor serialisation without a global lock.
     */
    public function up(): void
    {
        Schema::create('instructor_balances', function (Blueprint $table) {
            // One row per instructor; the instructor id IS the primary key, so a
            // second balance row for the same instructor cannot exist.
            $table->foreignId('instructor_id')->primary()->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('total_earned_minor')->default(0);
            $table->unsignedBigInteger('total_paid_minor')->default(0);

            // SIGNED: a refund clawback against an already-paid instructor legitimately
            // drives this negative. That is a meaningful state — the instructor owes
            // the platform — and is netted against future earnings, not an error.
            $table->bigInteger('outstanding_minor')->default(0);

            // High-water mark of the ledger rows folded into these totals.
            $table->unsignedBigInteger('last_ledger_entry_id')->default(0);

            $table->timestamps();

            // Finding payable instructors without scanning the ledger.
            $table->index('outstanding_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
