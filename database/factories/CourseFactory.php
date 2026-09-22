<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'title' => Str::headline(fake()->unique()->words(3, true)),
        ];
    }
}
