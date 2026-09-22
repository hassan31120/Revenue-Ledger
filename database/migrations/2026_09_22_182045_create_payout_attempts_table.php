<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every single conversation with the payment provider, append-only.
     *
     * Including the ones that timed out and the ones that were only status
     * lookups. When an auditor asks "why does this payout say paid when the job
     * log shows an error", this table is the answer.
     */
    public function up(): void
    {
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('attempt_no');

            $table->enum('kind', ['send', 'status_lookup']);

            $table->enum('outcome', [
                'success',            // provider confirmed the money moved
                'permanent_failure',  // provider refused; no money moved
                'timeout',            // no answer; outcome UNKNOWN
                'not_found',          // provider has no record of it
                'pending',            // provider has it but has not finished
            ]);

            $table->string('idempotency_key_sent');
            $table->string('provider_reference')->nullable();
            $table->text('detail')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['payout_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_attempts');
    }
};
