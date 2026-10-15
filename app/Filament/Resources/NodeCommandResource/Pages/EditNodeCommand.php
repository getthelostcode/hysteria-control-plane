<?php

namespace App\Filament\Resources\NodeCommandResource\Pages;

use App\Filament\Resources\NodeCommandResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNodeCommand extends EditRecord
{
    protected static string $resource = NodeCommandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
