<?php

namespace App\Filament\Resources\UpgradePackageResource\Pages;

use App\Filament\Resources\UpgradePackageResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUpgradePackage extends ViewRecord
{
    protected static string $resource = UpgradePackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
