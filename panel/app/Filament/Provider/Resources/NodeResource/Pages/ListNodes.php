<?php

namespace App\Filament\Provider\Resources\NodeResource\Pages;

use App\Filament\Provider\Resources\NodeResource;
use Filament\Resources\Pages\ListRecords;

class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;

    protected function getHeaderActions(): array
    {
        // 节点通过 register-hysteria/install.sh 注册产生
        return [];
    }
}
