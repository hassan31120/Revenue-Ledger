<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanCode;
use Illuminate\Database\Eloquent\Factories\Factory;

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

    private function priceFor(PlanCode $code): int
    {
        return match ($code) {
            PlanCode::Monthly => 19999,
            PlanCode::Quarterly => 49999,
            PlanCode::Annual => 179999,
        };
    }
}
