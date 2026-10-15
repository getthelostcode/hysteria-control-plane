<?php

namespace App\Filament\Resources\UpgradePackageResource\Pages;

use App\Filament\Resources\UpgradePackageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUpgradePackage extends EditRecord
{
    protected static string $resource = UpgradePackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
