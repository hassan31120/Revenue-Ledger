<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorBalanceResource\Pages;

use App\Filament\Resources\InstructorBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorBalances extends ListRecords
{
    protected static string $resource = InstructorBalanceResource::class;

    /**
     * No header actions: nothing on this screen may create or change money.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
