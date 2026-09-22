<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            $table->enum('status', ['pending', 'processing', 'paid', 'failed', 'unknown'])
                ->default('pending');

            // Sent to the provider on every attempt for this payout, unchanged.
            //
            // Stored rather than derived, so that a retry — in a new process, after
            // a crash, days later — sends the IDENTICAL key and the provider
            // returns the original payment instead of moving money again.
            //
            // A new payout created after a genuine failure is a different logical
            // payment and correctly gets a different key.
            $table->string('provider_idempotency_key')->unique();

            // Only ever set once the provider has confirmed a payment.
            $table->string('provider_reference')->nullable()->unique();

            // The highest ledger entry id this payout covers. Not used to compute
            // the amount — it documents exactly which earnings were settled, which
            // is what an auditor asks for when a balance looks wrong.
            $table->unsignedBigInteger('ledger_cutoff_id');

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // When an "unknown" payout should next be asked about.
            $table->timestamp('reconcile_after')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'created_at']);

            // Drives both sweeps: unknown payouts due for reconciliation, and
            // payouts stuck in processing because a worker died.
            $table->index(['status', 'updated_at']);
        });

        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive CHECK (amount_minor > 0)');

        /*
         * The invariant that makes double-paying structurally impossible:
         * AT MOST ONE OPEN PAYOUT PER INSTRUCTOR.
         *
         * MySQL has no partial indexes, so a STORED generated column stands in for
         * one. While a payout is pending, processing or unknown it exposes its
         * instructor id; once it reaches a terminal state the expression yields
         * NULL. MySQL permits unlimited NULLs in a unique index, so payout HISTORY
         * is unbounded while only one live payout per instructor can exist.
         *
         * Note that 'unknown' counts as open. An instructor whose payout result is
         * unresolved is frozen until reconciliation settles it — which is what
         * stops a timed-out payment from being paid a second time.
         *
         * This holds with zero cooperation from the application. Two workers that
         * both decide to pay the same instructor produce one row and one error.
         */
        DB::statement("
            ALTER TABLE payouts
            ADD COLUMN open_instructor_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('pending', 'processing', 'unknown') THEN instructor_id END
                ) STORED
        ");

        DB::statement('ALTER TABLE payouts ADD UNIQUE KEY payouts_one_open_per_instructor (open_instructor_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
