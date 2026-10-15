<?php

declare(strict_types=1);

namespace App\Filament\Resources\NodeResource\Pages;

use App\Filament\Resources\NodeResource;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('checkOffline')
                ->label('检查离线节点')
                ->icon('heroicon-o-signal-slash')
                ->color('gray')
                ->action(function (): void {
                    Artisan::call('nodes:check-offline');

                    Notification::make()->title('已重新计算节点在线状态')->success()->send();
                }),
            Actions\Action::make('broadcastCommand')
                ->label('全网指令')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('create', NodeCommand::class) ?? false)
                ->form([
                    Forms\Components\Select::make('type')
                        ->label('指令类型')
                        ->options(NodeCommand::TYPES)
                        ->default(NodeCommand::TYPE_RELOAD)
                        ->required(),
                ])
                ->action(function (array $data, CommandService $commands): void {
                    $nodes = Node::query()->where('status', Node::STATUS_ONLINE)->get();
                    $issued = $commands->issueBulk($nodes, $data['type'], [], auth()->user());

                    Notification::make()
                        ->title(sprintf('已向 %d 个在线节点下发指令', $issued->count()))
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
