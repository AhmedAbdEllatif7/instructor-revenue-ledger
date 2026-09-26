<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayoutResource\Pages;
use App\Models\Payout;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payout History';

    protected static ?string $modelLabel = 'Payout';

    protected static ?string $pluralModelLabel = 'Payouts';

    protected static ?string $slug = 'payouts';

    protected static ?string $navigationGroup = 'Financial Management';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('instructor');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#ID')
                    ->sortable(),

                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->state(fn (Payout $record): string => number_format($record->amount_cents / 100, 2) . ' EGP')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Payout::STATUS_COMPLETED => 'success',
                        Payout::STATUS_PROCESSING => 'warning',
                        Payout::STATUS_PENDING_RECONCILIATION => 'warning',
                        Payout::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Payout::STATUS_COMPLETED => 'Completed',
                        Payout::STATUS_PROCESSING => 'Processing',
                        Payout::STATUS_PENDING_RECONCILIATION => 'Pending Reconciliation',
                        Payout::STATUS_FAILED => 'Failed',
                        Payout::STATUS_DRAFT => 'Draft',
                        default => ucfirst($state),
                    })
                    ->sortable(),

                TextColumn::make('period_key')
                    ->label('Period')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider_transfer_id')
                    ->label('Provider Ref')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('idempotency_key')
                    ->label('Idempotency Key')
                    ->copyable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('failure_reason')
                    ->label('Failure Reason')
                    ->limit(30)
                    ->tooltip(fn (Payout $record): ?string => $record->failure_reason)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processed_at')
                    ->label('Processed At')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Initiated At')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Payout::STATUS_COMPLETED => 'Completed',
                        Payout::STATUS_PROCESSING => 'Processing',
                        Payout::STATUS_PENDING_RECONCILIATION => 'Pending Reconciliation',
                        Payout::STATUS_FAILED => 'Failed',
                        Payout::STATUS_DRAFT => 'Draft',
                    ]),

                SelectFilter::make('period_key')
                    ->label('Accounting Period')
                    ->options(fn (): array => Payout::query()->distinct()->pluck('period_key', 'period_key')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Payout Information')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('id')->label('#ID'),
                            TextEntry::make('instructor.name')->label('Instructor Name')->weight('bold'),
                            TextEntry::make('period_key')->label('Accounting Period')->badge()->color('info'),
                            TextEntry::make('amount_cents')
                                ->label('Payout Amount')
                                ->state(fn (Payout $record): string => number_format($record->amount_cents / 100, 2) . ' EGP')
                                ->badge()
                                ->color('success'),
                            TextEntry::make('status')
                                ->label('Current Status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    Payout::STATUS_COMPLETED => 'success',
                                    Payout::STATUS_PROCESSING => 'warning',
                                    Payout::STATUS_PENDING_RECONCILIATION => 'warning',
                                    Payout::STATUS_FAILED => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('provider_transfer_id')
                                ->label('Provider Transfer ID')
                                ->placeholder('—')
                                ->copyable(),
                        ]),
                    ]),

                Section::make('Audit & Diagnostic Details')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('idempotency_key')
                                ->label('Unique Idempotency Key')
                                ->copyable(),
                            TextEntry::make('failure_reason')
                                ->label('Failure Reason')
                                ->placeholder('None (Successful payout)'),
                            TextEntry::make('processed_at')
                                ->label('Processed Timestamp')
                                ->dateTime()
                                ->placeholder('—'),
                            TextEntry::make('reconciled_at')
                                ->label('Reconciled Timestamp')
                                ->dateTime()
                                ->placeholder('—'),
                        ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayouts::route('/'),
            'view' => Pages\ViewPayout::route('/{record}'),
        ];
    }
}
