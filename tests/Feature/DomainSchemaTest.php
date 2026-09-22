<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Schema invariants
|--------------------------------------------------------------------------
|
| These tests do not exercise application code. They exist to prove that the
| DATABASE refuses invalid financial data on its own — because every later claim
| about idempotency and concurrency safety rests on the database being the
| referee, not the application.
|
| Each one writes raw SQL deliberately, bypassing Eloquent, so that a future
| model-level guard cannot make the test pass while the constraint is missing.
|
*/

it('refuses a plan with a zero-month duration', function () {
    // Refund proration divides by the term length; a zero-length term is unusable.
    expect(fn () => DB::table('plans')->insert([
        'code' => 'broken', 'name' => 'Broken', 'duration_months' => 0,
        'price_minor' => 1000, 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a negative plan price', function () {
    expect(fn () => DB::table('plans')->insert([
        'code' => 'negative', 'name' => 'Negative', 'duration_months' => 1,
        'price_minor' => -1, 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a platform fee above 100 percent', function () {
    // 10001 bps would allocate negative money to instructors.
    expect(fn () => Subscription::factory()->platformFeeBps(10001)->create())
        ->toThrow(QueryException::class);
});

it('accepts a platform fee of exactly 100 percent', function () {
    // The boundary is legitimate: the platform keeps everything, instructors get zero.
    $subscription = Subscription::factory()->platformFeeBps(10000)->create();

    expect($subscription->platform_fee_bps)->toBe(10000);
});

it('refuses a subscription whose term ends before it starts', function () {
    expect(fn () => DB::table('subscriptions')->insert([
        'student_id' => Student::factory()->create()->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => 'active',
        'gross_amount_minor' => 1000,
        'currency' => 'EGP',
        'platform_fee_bps' => 3000,
        'payment_reference' => 'pay_backwards',
        'purchased_at' => '2026-01-01 00:00:00',
        'starts_at' => '2026-06-01 00:00:00',
        'ends_at' => '2026-01-01 00:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to book the same provider payment as revenue twice', function () {
    $first = Subscription::factory()->create(['payment_reference' => 'pay_duplicate']);

    expect(fn () => Subscription::factory()->create(['payment_reference' => 'pay_duplicate']))
        ->toThrow(QueryException::class);

    expect(Subscription::where('payment_reference', 'pay_duplicate')->count())->toBe(1)
        ->and($first->exists)->toBeTrue();
});

it('refuses to grant the same course to a subscription twice', function () {
    $subscription = Subscription::factory()->create();
    $course = Course::factory()->create();

    DB::table('subscription_courses')->insert([
        'subscription_id' => $subscription->id, 'course_id' => $course->id, 'created_at' => now(),
    ]);

    expect(fn () => DB::table('subscription_courses')->insert([
        'subscription_id' => $subscription->id, 'course_id' => $course->id, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('stores a subscription term that ends beyond the 2038 timestamp ceiling', function () {
    // An annual plan bought in 2037 ends in 2038. TIMESTAMP columns cannot hold
    // that, which is why these three columns are DATETIME.
    $plan = Plan::factory()->code(PlanCode::Annual)->create();

    $subscription = Subscription::factory()->forPlan($plan)->create([
        'purchased_at' => '2037-06-01 00:00:00',
        'starts_at' => '2037-06-01 00:00:00',
        'ends_at' => '2038-06-01 00:00:00',
    ]);

    expect($subscription->fresh()->ends_at->format('Y-m-d'))->toBe('2038-06-01');
});

it('derives the participating instructors from the courses captured at purchase', function () {
    $instructors = Instructor::factory()->count(3)->create();
    $courses = $instructors->map(fn (Instructor $i) => Course::factory()->for($i)->create());

    $subscription = Subscription::factory()->withCourses($courses)->create();

    $participating = $subscription->courses()
        ->pluck('courses.instructor_id')->unique()->sort()->values()->all();

    expect($participating)->toBe($instructors->pluck('id')->sort()->values()->all());
});
