<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstructorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Instructor Balances';

    protected static ?string $modelLabel = 'Instructor Balance';

    protected static ?string $pluralModelLabel = 'Instructor Balances';

    protected static ?string $slug = 'instructor-balances';

    protected static ?string $navigationGroup = 'Financial Management';

    protected static ?int $navigationSort = 1;

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
        return parent::getEloquentQuery()
            ->where('role', User::ROLE_INSTRUCTOR)
            ->withCount('courses');
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

                TextColumn::make('name')
                    ->label('Instructor Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('courses_count')
                    ->label('Courses')
                    ->counts('courses')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('withdrawable_balance')
                    ->label('Available Balance')
                    ->state(fn (User $record): string => number_format(app(LedgerService::class)->getInstructorBalanceCents($record) / 100, 2) . ' EGP')
                    ->badge()
                    ->color(fn (User $record): string => app(LedgerService::class)->getInstructorBalanceCents($record) > 0 ? 'success' : 'gray'),

                TextColumn::make('total_earnings')
                    ->label('Lifetime Earnings')
                    ->state(fn (User $record): string => number_format(app(LedgerService::class)->getInstructorTotalEarningsCents($record) / 100, 2) . ' EGP')
                    ->badge()
                    ->color('info'),

                TextColumn::make('escrow_hold')
                    ->label('In-Flight Escrow')
                    ->state(function (User $record): string {
                        $cents = app(LedgerService::class)->getAccountBalanceCents("liabilities:instructor:payout_in_flight:{$record->id}");
                        return number_format($cents / 100, 2) . ' EGP';
                    })
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Read-only ledger views
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
                Section::make('Instructor Details')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('name')
                                ->label('Full Name')
                                ->weight('bold'),
                            TextEntry::make('email')
                                ->label('Email Address')
                                ->copyable(),
                            TextEntry::make('courses_count')
                                ->label('Total Courses')
                                ->state(fn (User $record): int => $record->courses()->count()),
                        ]),
                    ]),

                Section::make('Double-Entry Ledger Balances')
                    ->icon('heroicon-o-scale')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('withdrawable_balance')
                                ->label('Available Withdrawable Balance')
                                ->state(fn (User $record): string => number_format(app(LedgerService::class)->getInstructorBalanceCents($record) / 100, 2) . ' EGP')
                                ->badge()
                                ->color('success'),

                            TextEntry::make('total_earnings')
                                ->label('Lifetime Platform Earnings')
                                ->state(fn (User $record): string => number_format(app(LedgerService::class)->getInstructorTotalEarningsCents($record) / 100, 2) . ' EGP')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('escrow_hold')
                                ->label('In-Flight Payout Escrow')
                                ->state(function (User $record): string {
                                    $cents = app(LedgerService::class)->getAccountBalanceCents("liabilities:instructor:payout_in_flight:{$record->id}");
                                    return number_format($cents / 100, 2) . ' EGP';
                                })
                                ->badge()
                                ->color('warning'),
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
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }
}
