<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('attempt_no');

            $table->enum('kind', ['send', 'status_lookup']);

            $table->enum('outcome', [
                'success',
                'permanent_failure',
                'timeout',
                'not_found',
                'pending',
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
