<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->foreignId('instructor_id')->primary()->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('total_earned_minor')->default(0);
            $table->unsignedBigInteger('total_paid_minor')->default(0);

            $table->bigInteger('outstanding_minor')->default(0);

            $table->unsignedBigInteger('last_ledger_entry_id')->default(0);

            $table->timestamps();

            $table->index('outstanding_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
