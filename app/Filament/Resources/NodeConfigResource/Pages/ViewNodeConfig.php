<?php

namespace App\Filament\Resources\NodeConfigResource\Pages;

use App\Filament\Resources\NodeConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNodeConfig extends ViewRecord
{
    protected static string $resource = NodeConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
