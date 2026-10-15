<?php

declare(strict_types=1);

namespace App\Filament\Resources\NodeConfigResource\Pages;

use App\Filament\Resources\NodeConfigResource;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * 查看某个配置版本。
 *
 * 这里提供「编辑正文」与「设为默认下发」两个动作 —— 都走 ConfigService，
 * 保证 sha256 重算、历史与审计一致（直接在表单里存模型会绕过这些）。
 */
class ViewNodeConfig extends ViewRecord
{
    protected static string $resource = NodeConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit_content')
                ->label('编辑正文')
                ->icon('heroicon-o-pencil-square')
                ->fillForm(fn (NodeConfig $record): array => ['content_yaml' => $record->content_yaml])
                ->form([
                    Forms\Components\Textarea::make('content_yaml')
                        ->label('配置正文（YAML）')
                        ->rows(20)
                        ->required()
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px;']),
                    Forms\Components\TextInput::make('reason')->label('修改原因')->default('panel edit'),
                ])
                ->action(function (NodeConfig $record, array $data, ConfigService $configs): void {
                    $updated = $configs->updateContent($record, (string) $data['content_yaml'], auth()->user(), (string) ($data['reason'] ?? ''));

                    Notification::make()
                        ->title('v' . $updated->version . ' 正文已更新')
                        ->body('sha256 ' . $updated->shortHash())
                        ->success()
                        ->send();
                }),
            Actions\Action::make('activate')
                ->label('设为默认下发')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('success')
                ->visible(fn (NodeConfig $record): bool => ! $record->is_current)
                ->requiresConfirmation()
                ->modalDescription('把这一版设为该节点默认下发的版本：节点下一次同步/轮询就会取走它的内容。')
                ->action(function (NodeConfig $record, ConfigService $configs): void {
                    $current = $configs->setCurrent($record->node, $record->version, auth()->user(), '指定下发版本');

                    Notification::make()
                        ->title('v' . $current->version . ' 已设为默认下发')
                        ->success()
                        ->send();
                }),
        ];
    }
}
