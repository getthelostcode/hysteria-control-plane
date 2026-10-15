<?php

namespace App\Filament\Resources\NodeCommandResource\Pages;

use App\Filament\Resources\NodeCommandResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNodeCommand extends ViewRecord
{
    protected static string $resource = NodeCommandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
