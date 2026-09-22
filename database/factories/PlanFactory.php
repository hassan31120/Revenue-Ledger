<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->randomElement(PlanCode::cases());

        return [
            'code' => $code,
            'name' => $code->label(),
            'duration_months' => $code->durationMonths(),
            'price_minor' => $this->priceFor($code),
            'currency' => config('revenue.currency'),
        ];
    }

    public function code(PlanCode $code): static
    {
        return $this->state(fn () => [
            'code' => $code,
            'name' => $code->label(),
            'duration_months' => $code->durationMonths(),
            'price_minor' => $this->priceFor($code),
        ]);
    }

    /**
     * Deliberately awkward prices, in minor units. EGP 499.99 over three
     * instructors does not divide evenly — which is exactly the case the
     * allocator has to get right.
     */
    private function priceFor(PlanCode $code): int
    {
        return match ($code) {
            PlanCode::Monthly => 19999,   // EGP 199.99
            PlanCode::Quarterly => 49999, // EGP 499.99
            PlanCode::Annual => 179999,   // EGP 1,799.99
        };
    }
}
