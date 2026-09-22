<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A demo dataset large enough to be interesting and small enough to seed quickly.
 *
 * The shape matters more than the size: every subscription deliberately spans
 * courses from SEVERAL instructors, and the plan prices are chosen so that the
 * instructor pool rarely divides evenly. Remainder handling is therefore exercised
 * by ordinary seeded data, not only by contrived tests.
 */
class DatabaseSeeder extends Seeder
{
    private const INSTRUCTORS = 12;

    private const COURSES_PER_INSTRUCTOR = 3;

    private const STUDENTS = 150;

    private const SUBSCRIPTIONS = 200;

    public function run(): void
    {
        $this->seedAdminUser();

        $plans = $this->seedPlans();

        $instructors = Instructor::factory()->count(self::INSTRUCTORS)->create();

        $courses = $instructors->flatMap(
            fn (Instructor $instructor) => Course::factory()
                ->count(self::COURSES_PER_INSTRUCTOR)
                ->for($instructor)
                ->create()
        );

        $students = Student::factory()->count(self::STUDENTS)->create();

        $this->seedSubscriptions($plans, $students, $courses);

        // Allocate the seeded payments, so a fresh database already has a ledger,
        // instructor balances and payable outstanding amounts to demonstrate with.
        Artisan::call('revenue:allocate');

        $this->command?->info('Admin login: admin@example.test / password');

        $this->command?->info(sprintf(
            'Seeded %d instructors, %d courses, %d students, %d subscriptions.',
            $instructors->count(),
            $courses->count(),
            $students->count(),
            Subscription::count(),
        ));
    }

    /**
     * The Filament panel needs somebody to log in as. Credentials are printed so
     * a fresh clone is usable immediately; this seeder never runs in production.
     */
    private function seedAdminUser(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'Ledger Admin', 'password' => Hash::make('password')],
        );
    }

    /**
     * @return Collection<int, Plan>
     */
    private function seedPlans(): Collection
    {
        return collect(PlanCode::cases())->map(fn (PlanCode $code) => Plan::firstOrCreate(
            ['code' => $code],
            Plan::factory()->code($code)->make()->only(['name', 'duration_months', 'price_minor', 'currency']),
        ));
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Course>  $courses
     */
    private function seedSubscriptions($plans, $students, $courses): void
    {
        $now = Carbon::now();
        $rows = [];

        for ($i = 0; $i < self::SUBSCRIPTIONS; $i++) {
            $plan = $plans->random();
            $purchasedAt = $now->copy()->subDays(random_int(1, 300))->startOfDay();

            $rows[] = [
                'student_id' => $students->random()->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'gross_amount_minor' => $plan->price_minor,
                'currency' => $plan->currency,
                'platform_fee_bps' => (int) config('revenue.platform_fee_bps'),
                'payment_reference' => 'pay_'.Str::lower(Str::random(20)),
                'purchased_at' => $purchasedAt,
                'starts_at' => $purchasedAt,
                'ends_at' => $purchasedAt->copy()->addMonths($plan->duration_months),
                'created_at' => $purchasedAt,
                'updated_at' => $purchasedAt,
            ];
        }

        // One bulk insert rather than 200 round trips. The same habit is what keeps
        // this system viable at 500k subscriptions.
        foreach (array_chunk($rows, 100) as $chunk) {
            Subscription::insert($chunk);
        }

        $this->attachCourses($courses);
    }

    /**
     * Give every subscription access to courses from 2-4 DIFFERENT instructors,
     * so that revenue genuinely has to be split.
     *
     * @param  Collection<int, Course>  $courses
     */
    private function attachCourses($courses): void
    {
        $byInstructor = $courses->groupBy('instructor_id');
        $pivot = [];

        Subscription::query()
            ->select('id')
            ->chunkById(100, function ($subscriptions) use ($byInstructor, &$pivot) {
                foreach ($subscriptions as $subscription) {
                    $instructorIds = $byInstructor->keys()->random(random_int(2, 4));

                    foreach ($instructorIds as $instructorId) {
                        $pivot[] = [
                            'subscription_id' => $subscription->id,
                            'course_id' => $byInstructor[$instructorId]->random()->id,
                            'created_at' => now(),
                        ];
                    }
                }
            });

        foreach (array_chunk($pivot, 500) as $chunk) {
            DB::table('subscription_courses')->insert($chunk);
        }
    }
}
