<?php

declare(strict_types=1);

namespace App\Filament\Resources\NodeResource\Pages;

use App\Filament\Resources\NodeResource;
use App\Filament\Widgets\NodeDetailWidget;
use App\Models\NodeCommand;
use App\Services\CommandService;
use App\Services\NodeService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewNode extends ViewRecord
{
    protected static string $resource = NodeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var \App\Models\Node $node */
        $node = $this->getRecord();

        NodeDetailWidget::$nodeId = $node->id;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('issueCommand')
                ->label('下发指令')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('issueCommand', $this->getRecord()) ?? false)
                ->form([
                    Forms\Components\Select::make('type')
                        ->label('指令类型')
                        ->options(NodeCommand::TYPES)
                        ->default(NodeCommand::TYPE_RESTART)
                        ->required(),
                    Forms\Components\KeyValue::make('payload')->label('参数'),
                ])
                ->action(function (array $data): void {
                    $command = app(CommandService::class)
                        ->issue($this->getRecord(), $data['type'], $data['payload'] ?? [], auth()->user());

                    Notification::make()->title('指令已下发')->body($command->command_id)->success()->send();
                }),
            Actions\Action::make('rotateSecret')
                ->label('轮换密钥')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('旧密钥立即失效，节点必须使用新密钥重新签名。')
                ->visible(fn (): bool => auth()->user()?->can('rotateSecret', $this->getRecord()) ?? false)
                ->action(function (): void {
                    $secret = app(NodeService::class)->rotateSecret($this->getRecord(), auth()->user());

                    Notification::make()
                        ->title('新密钥（仅显示一次）')
                        ->body($secret)
                        ->persistent()
                        ->success()
                        ->send();
                }),
            Actions\EditAction::make(),
        ];
    }

    /** @return array<int, class-string> */
    protected function getFooterWidgets(): array
    {
        return [NodeDetailWidget::class];
    }
}
