<?php

namespace App\Filament\Resources\EnrollTokenResource\Pages;

use App\Filament\Resources\EnrollTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEnrollTokens extends ListRecords
{
    protected static string $resource = EnrollTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
