<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public static function earnedMinor(int $instructorId): int
    {
        return (int) static::query()
            ->forInstructor($instructorId)
            ->ofType(LedgerEntryType::Earning)
            ->sum('amount_minor');
    }

    public static function paidMinor(int $instructorId): int
    {
        return -(int) static::query()
            ->forInstructor($instructorId)
            ->ofType(LedgerEntryType::Payout)
            ->sum('amount_minor');
    }

    public static function outstandingMinor(int $instructorId): int
    {
        return (int) static::query()
            ->forInstructor($instructorId)
            ->sum('amount_minor');
    }
}
