<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Instructor extends Model
{
    /** @use HasFactory<InstructorFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'payout_account_ref'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Append-only financial history. This is the source of truth for what the
     * instructor has earned, been paid, and is still owed.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Denormalised running totals. An accelerator for finding payable instructors
     * and a row to lock during payout claiming — NOT the source of truth. Any
     * disagreement with the ledger is a bug, and `ledger:verify` reports it.
     */
    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }
}
