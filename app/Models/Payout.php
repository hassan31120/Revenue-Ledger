<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutStatus;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'ledger_cutoff_id' => 'integer',
            'attempts' => 'integer',
            'reconcile_after' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function attemptLog(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }

    /**
     * The ledger entry key for this payout's debit.
     *
     * Derived from the payout id, so it is identical on every retry and the
     * unique index on ledger_entries guarantees the debit lands exactly once —
     * even if two workers both conclude the payment succeeded.
     */
    public function ledgerIdempotencyKey(): string
    {
        return "payout:{$this->id}";
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayoutStatus::Pending->value,
            PayoutStatus::Processing->value,
            PayoutStatus::Unknown->value,
        ]);
    }

    /**
     * Unknown payouts whose reconciliation is now due.
     */
    public function scopeDueForReconciliation(Builder $query): Builder
    {
        return $query->where('status', PayoutStatus::Unknown->value)
            ->where(fn ($q) => $q->whereNull('reconcile_after')->orWhere('reconcile_after', '<=', now()));
    }

    /**
     * Payouts left mid-flight by a worker that died.
     */
    public function scopeStaleProcessing(Builder $query, int $olderThanMinutes): Builder
    {
        return $query->where('status', PayoutStatus::Processing->value)
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes));
    }
}
