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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('duration_months');

            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);

            $table->timestamps();
        });

        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_duration_positive CHECK (duration_months > 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_non_negative CHECK (price_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
