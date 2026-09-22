<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            // A course has exactly one instructor. This is what makes the set of
            // instructors participating in a subscription derivable.
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->timestamps();

            $table->index('instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
