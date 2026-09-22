<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each instructor was allocated from one subscription, and on what basis.
     *
     * This is the WEIGHTS SNAPSHOT. A refund years later re-runs the identical
     * allocation using the weights and fee recorded here, so a clawback mirrors
     * the original split exactly — even if the catalog, the instructor roster or
     * the platform's commission have all changed since.
     */
    public function up(): void
    {
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            // The basis on which this instructor's share was computed. Today every
            // participant weighs 1 (an equal split). The column exists because the
            // allocator already consumes arbitrary weights, so moving to watch-time
            // or course-count weighting changes only how weights are RESOLVED —
            // not the money arithmetic, and not this schema.
            $table->unsignedInteger('weight');

            $table->unsignedBigInteger('amount_minor');

            // Snapshot of the commission actually applied, copied from the
            // subscription. Kept here so a refund never has to re-derive it.
            $table->unsignedSmallInteger('platform_fee_bps');

            $table->timestamp('created_at')->useCurrent();

            // Allocation idempotency, enforced by the database.
            //
            // Running allocation twice does not "check and skip" — the second run
            // is REJECTED here. That distinction matters under concurrency, where
            // two workers can both read "not yet allocated" before either writes.
            $table->unique(['subscription_id', 'instructor_id']);

            $table->index('instructor_id');
        });

        DB::statement('ALTER TABLE revenue_allocations ADD CONSTRAINT revenue_allocations_weight_positive CHECK (weight > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
