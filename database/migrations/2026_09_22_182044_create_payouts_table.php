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

            $table->string('provider_idempotency_key')->unique();

            $table->string('provider_reference')->nullable()->unique();

            $table->unsignedBigInteger('ledger_cutoff_id');

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('reconcile_after')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['instructor_id', 'created_at']);

            $table->index(['status', 'updated_at']);
        });

        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive CHECK (amount_minor > 0)');

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
