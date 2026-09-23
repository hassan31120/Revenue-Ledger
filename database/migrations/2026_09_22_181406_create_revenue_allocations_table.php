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
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('weight');

            $table->unsignedBigInteger('amount_minor');

            $table->unsignedSmallInteger('platform_fee_bps');

            $table->timestamp('created_at')->useCurrent();

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
