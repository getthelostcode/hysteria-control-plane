<?php

namespace App\Filament\Resources\NodeLogResource\Pages;

use App\Filament\Resources\NodeLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNodeLogs extends ListRecords
{
    protected static string $resource = NodeLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
