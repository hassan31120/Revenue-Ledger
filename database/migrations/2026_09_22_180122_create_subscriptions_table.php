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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->enum('status', ['active', 'partially_refunded', 'refunded', 'expired'])
                ->default('active');

            // The full term is paid upfront. Integer minor units, never a decimal.
            $table->unsignedBigInteger('gross_amount_minor');
            $table->char('currency', 3);

            // SNAPSHOT of the platform commission at purchase time. Kept on the row
            // rather than read from config at allocation time, so that changing the
            // platform fee can never retroactively alter money already allocated —
            // and so a refund years later splits exactly as the original purchase did.
            $table->unsignedSmallInteger('platform_fee_bps');

            // The external payment that created this subscription. Unique, so the
            // same provider payment can never be booked as revenue twice.
            $table->string('payment_reference')->unique();

            /*
             * DATETIME, not TIMESTAMP.
             *
             * These are forward-dated business instants: an annual subscription
             * bought in 2037 ends in 2038, past the TIMESTAMP ceiling of
             * 2038-01-19, and MySQL would refuse to store it. DATETIME also stores
             * the value verbatim instead of converting it through a session
             * timezone, so what an auditor reads is exactly what was written.
             *
             * All three are written in UTC (see the connection timezone in
             * config/database.php).
             */
            $table->dateTime('purchased_at');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->timestamps();

            // Drives expiry sweeps and refund proration lookups.
            $table->index(['status', 'ends_at']);
        });

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_gross_non_negative CHECK (gross_amount_minor >= 0)');

        // Basis points: 10000 = 100%. A fee above 100% would allocate negative
        // money to instructors, so the database refuses to store one.
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_fee_bps_range CHECK (platform_fee_bps BETWEEN 0 AND 10000)');

        // A term must have positive length: refund proration divides by it.
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_term_ordered CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
