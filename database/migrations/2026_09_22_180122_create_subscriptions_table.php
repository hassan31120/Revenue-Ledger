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

            $table->unsignedBigInteger('gross_amount_minor');
            $table->char('currency', 3);

            $table->unsignedSmallInteger('platform_fee_bps');

            $table->string('payment_reference')->unique();

            $table->dateTime('purchased_at');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->timestamps();

            $table->index(['status', 'ends_at']);
        });

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_gross_non_negative CHECK (gross_amount_minor >= 0)');

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_fee_bps_range CHECK (platform_fee_bps BETWEEN 0 AND 10000)');

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_term_ordered CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
