<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable financial fact.
 *
 * Rows are written exclusively through App\Services\Ledger. This model is for
 * READING: the database refuses updates and deletes outright (see the triggers in
 * the create_ledger_entries_table migration), so there is deliberately no code
 * here that would let a caller believe otherwise.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'entry_type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'source_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeOfType(Builder $query, LedgerEntryType ...$types): Builder
    {
        return $query->whereIn('entry_type', array_map(fn ($t) => $t->value, $types));
    }

    /**
     * Everything an instructor has ever been credited for subscriptions.
     */
    public static function earnedMinor(int $instructorId): int
    {
        return (int) static::query()
            ->forInstructor($instructorId)
            ->ofType(LedgerEntryType::Earning)
            ->sum('amount_minor');
    }

    /**
     * Everything actually confirmed as paid out. Payout entries are stored
     * negative, so this negates the sum to report a positive "paid" figure.
     */
    public static function paidMinor(int $instructorId): int
    {
        return -(int) static::query()
            ->forInstructor($instructorId)
            ->ofType(LedgerEntryType::Payout)
            ->sum('amount_minor');
    }

    /**
     * What the instructor is owed right now.
     *
     * Deliberately the plain sum of EVERY entry type, so refund clawbacks and
     * manual corrections reduce it automatically. It can be negative, which means
     * the instructor owes the platform.
     */
    public static function outstandingMinor(int $instructorId): int
    {
        return (int) static::query()
            ->forInstructor($instructorId)
            ->sum('amount_minor');
    }
}
