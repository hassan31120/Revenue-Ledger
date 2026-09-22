<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Running totals derived from the ledger.
 *
 * NOT the source of truth — an accelerator, and the row that payout claiming
 * locks. `ledger:verify` recomputes all of it from ledger_entries and reports any
 * drift, which would be a bug rather than an expected divergence.
 */
class InstructorBalance extends Model
{
    protected $primaryKey = 'instructor_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'instructor_id' => 'integer',
            'total_earned_minor' => 'integer',
            'total_paid_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'last_ledger_entry_id' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
