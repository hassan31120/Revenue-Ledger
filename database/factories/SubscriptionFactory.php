<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Plan;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $purchasedAt = Carbon::parse(fake()->dateTimeBetween('-10 months', 'now'))->startOfDay();

        return [
            'student_id' => Student::factory(),
            // Reuse an existing plan when there is one. Plan codes are a
            // three-value enum under a unique index, so minting a fresh plan for
            // every subscription collides as soon as a test creates several.
            'plan_id' => fn () => Plan::query()->orderBy('id')->value('id')
                ?? Plan::factory()->create()->id,
            'status' => SubscriptionStatus::Active,

            // Derived from whichever plan this subscription ends up on, including
            // one supplied via forPlan(). Resolved lazily so that overriding the
            // plan does not leave a second, orphaned plan behind.
            'gross_amount_minor' => fn (array $attributes) => $this->plan($attributes)->price_minor,
            'currency' => fn (array $attributes) => $this->plan($attributes)->currency,

            // Snapshot the commission as it stands at purchase time.
            'platform_fee_bps' => (int) config('revenue.platform_fee_bps'),

            'payment_reference' => 'pay_'.Str::lower(Str::random(20)),
            'purchased_at' => $purchasedAt,
            'starts_at' => $purchasedAt,
            'ends_at' => fn (array $attributes) => $purchasedAt->copy()
                ->addMonths($this->plan($attributes)->duration_months),
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }

    public function grossMinor(int $amountMinor): static
    {
        return $this->state(fn () => ['gross_amount_minor' => $amountMinor]);
    }

    public function platformFeeBps(int $bps): static
    {
        return $this->state(fn () => ['platform_fee_bps' => $bps]);
    }

    public function purchasedAt(Carbon $at): static
    {
        return $this->state(fn (array $attributes) => [
            'purchased_at' => $at,
            'starts_at' => $at,
            'ends_at' => $at->copy()->addMonths($this->plan($attributes)->duration_months),
        ]);
    }

    /**
     * Attach the courses whose instructors will share this subscription's revenue.
     *
     * @param  iterable<Course>  $courses
     */
    public function withCourses(iterable $courses): static
    {
        return $this->afterCreating(function (Subscription $subscription) use ($courses) {
            $subscription->courses()->syncWithoutDetaching(
                collect($courses)->pluck('id')->all()
            );
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes): Plan
    {
        return Plan::findOrFail($attributes['plan_id']);
    }
}
