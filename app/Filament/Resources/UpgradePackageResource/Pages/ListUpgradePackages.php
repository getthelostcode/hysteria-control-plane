<?php

namespace App\Filament\Resources\UpgradePackageResource\Pages;

use App\Filament\Resources\UpgradePackageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUpgradePackages extends ListRecords
{
    protected static string $resource = UpgradePackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
