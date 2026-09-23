<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PayoutStatus;
use App\Filament\Resources\PayoutResource\Pages;
use App\Models\Payout;
use App\Support\Money;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Payouts';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Payout ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (PayoutStatus $state): string => $state->label())
                    ->color(fn (PayoutStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider reference')
                    ->placeholder('—')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed at')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_error')
                    ->label('Last error')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn () => collect(PayoutStatus::cases())
                        ->mapWithKeys(fn (PayoutStatus $s) => [$s->value => $s->label()])
                        ->all()),

                Tables\Filters\Filter::make('needs_attention')
                    ->label('Unresolved — needs reconciliation')
                    ->query(fn (Builder $query) => $query->where('status', PayoutStatus::Unknown->value)),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('instructor');
    }

    public static function getNavigationBadge(): ?string
    {
        $unknown = static::getModel()::where('status', PayoutStatus::Unknown->value)->count();

        return $unknown > 0 ? (string) $unknown : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayouts::route('/')];
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
