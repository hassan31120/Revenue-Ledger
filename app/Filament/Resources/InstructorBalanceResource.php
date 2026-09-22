<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorBalanceResource\Pages;
use App\Models\InstructorBalance;
use App\Support\Money;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Instructor balances — read only.
 *
 * Deliberately inert. There is no form, no action, and no mutation path of any
 * kind: money moves through Actions and console commands, never through a screen.
 * A reviewer should be able to confirm that at a glance.
 *
 * The figures come from the instructor_balances projection rather than from a
 * live sum of the ledger, because summing tens of millions of rows to paint a
 * table is not viable. `ledger:verify` is what guarantees the two agree.
 */
class InstructorBalanceResource extends Resource
{
    protected static ?string $model = InstructorBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor Balances';

    protected static ?string $modelLabel = 'Instructor Balance';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_earned_minor')
                    ->label('Total earned')
                    ->alignEnd()
                    ->sortable()
                    // Minor units become a human-readable string HERE, at the very
                    // edge of the system, and nowhere else.
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),

                Tables\Columns\TextColumn::make('total_paid_minor')
                    ->label('Total paid')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),

                Tables\Columns\TextColumn::make('outstanding_minor')
                    ->label('Outstanding')
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    // A negative balance means the instructor owes the platform
                    // after a refund. It is a real state, not an error — but it
                    // should be obvious at a glance.
                    ->color(fn (int $state): string => match (true) {
                        $state < 0 => 'danger',
                        $state === 0 => 'gray',
                        default => 'success',
                    })
                    ->description(fn (InstructorBalance $record): ?string => $record->outstanding_minor < 0
                        ? 'Owes the platform; netted against future earnings'
                        : null),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last movement')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('outstanding')
                    ->label('Has an outstanding balance')
                    ->query(fn (Builder $query) => $query->where('outstanding_minor', '>', 0)),

                Tables\Filters\Filter::make('in_debt')
                    ->label('Owes the platform')
                    ->query(fn (Builder $query) => $query->where('outstanding_minor', '<', 0)),
            ])
            ->defaultSort('outstanding_minor', 'desc')
            // Server-side pagination: the table never loads more than a page,
            // however many instructors exist.
            ->paginated([25, 50, 100])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('instructor');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInstructorBalances::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
