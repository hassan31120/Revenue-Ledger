<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which courses a subscription granted access to, captured at purchase time.
     *
     * This is deliberately a snapshot rather than a live query against the catalog.
     * If a course is later reassigned to a different instructor, or removed from a
     * bundle, the money already allocated for this subscription must not change.
     * The participating instructors are derived from THIS table, not from today's
     * catalog.
     */
    public function up(): void
    {
        Schema::create('subscription_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['subscription_id', 'course_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_courses');
    }
};
