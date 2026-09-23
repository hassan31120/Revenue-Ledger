<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'duration_months', 'price_minor', 'currency'];

    protected function casts(): array
    {
        return [
            'code' => PlanCode::class,
            'duration_months' => 'integer',

            'price_minor' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
