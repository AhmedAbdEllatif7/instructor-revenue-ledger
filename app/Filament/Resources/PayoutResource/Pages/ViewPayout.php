<?php

namespace App\Filament\Resources\PayoutResource\Pages;

use App\Filament\Resources\PayoutResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPayout extends ViewRecord
{
    protected static string $resource = PayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
