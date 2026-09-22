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
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('reason');

            // When the money went back to the student. DATETIME for the same
            // reason the subscription term columns are: no timezone conversion on
            // a financial instant.
            $table->dateTime('refunded_at');

            // The external refund that this record corresponds to. Unique, so the
            // same refund can never be clawed back from instructors twice.
            $table->string('provider_refund_reference')->unique();

            $table->timestamp('created_at')->useCurrent();

            $table->index('subscription_id');
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
