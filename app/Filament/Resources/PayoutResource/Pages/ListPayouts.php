<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayoutResource\Pages;

use App\Filament\Resources\PayoutResource;
use Filament\Resources\Pages\ListRecords;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;

    /**
     * No header actions: payouts are created by the payout command and resolved
     * by reconciliation, never from a screen.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
