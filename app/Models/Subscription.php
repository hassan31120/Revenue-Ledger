<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'plan_id',
        'status',
        'gross_amount_minor',
        'currency',
        'platform_fee_bps',
        'payment_reference',
        'purchased_at',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            // Minor units and basis points are both integers by construction.
            'gross_amount_minor' => 'integer',
            'platform_fee_bps' => 'integer',
            'purchased_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The courses this subscription granted access to, as captured at purchase.
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'subscription_courses');
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Total length of the paid term, in whole days.
     *
     * Refund proration divides by this, and the database guarantees it is
     * positive (see the subscriptions_term_ordered CHECK constraint).
     */
    public function termDays(): int
    {
        return (int) $this->starts_at->diffInDays($this->ends_at);
    }
}
