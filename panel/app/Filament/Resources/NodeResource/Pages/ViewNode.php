<?php

namespace App\Filament\Resources\NodeResource\Pages;

use App\Filament\Resources\NodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNode extends ViewRecord
{
    protected static string $resource = NodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('编辑'),
        ];
    }
}
