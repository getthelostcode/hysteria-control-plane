<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ConfigFile;
use App\Services\ConfigFileService;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * 「配置管理 → 版本管理」：**配置版本文件库**。
 *
 * 只管文件，不管节点 —— 每条记录是一份与节点无关的配置正文版本（v1、v2…各一份），
 * 可创建、可编辑、可指定「默认版本」；给节点下发配置时从这里挑一份内容即可。
 * （某台节点下发的第几版在「节点管理 → 配置版本」里看。）
 */
class ConfigFiles extends Page implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationGroup = '配置管理';

    protected static ?string $navigationLabel = '版本管理';

    protected static ?string $title = '配置版本文件';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.config-files';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view nodes') ?? false;
    }

    public function getSubheading(): ?string
    {
        $current = app(ConfigFileService::class)->default();

        return $current === null
            ? '与节点无关的配置版本文件：先在这里攒版本，下发时挑一份即可。'
            : '与节点无关的配置版本文件；当前默认版本：' . $current->label() . '（下发时默认取它）。';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ConfigFile::query())
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->label('版本')
                    ->formatStateUsing(fn (ConfigFile $record): string => $record->label())
                    ->badge()
                    ->color(fn (ConfigFile $record): string => $record->is_default ? 'success' : 'gray')
                    ->tooltip(fn (ConfigFile $record): string => $record->is_default ? '当前默认版本（下发时默认取它）' : '')
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')->label('备注')->placeholder('-')->wrap(),
                Tables\Columns\TextColumn::make('sha256')
                    ->label('sha256')
                    ->formatStateUsing(fn (ConfigFile $record): string => $record->shortHash())
                    ->copyable()
                    ->copyableState(fn (ConfigFile $record): string => (string) $record->sha256)
                    ->tooltip(fn (ConfigFile $record): string => (string) $record->sha256)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('发布时间')->since()->sortable()
                    ->tooltip(fn (ConfigFile $record): ?string => $record->created_at?->toDateTimeString()),
                Tables\Columns\TextColumn::make('updated_at')->label('最后修改时间')->since()->sortable()
                    ->tooltip(fn (ConfigFile $record): ?string => $record->updated_at?->toDateTimeString()),
            ])
            ->defaultSort('version')
            ->paginationPageOptions([25, 50, 100])
            ->actions([
                Tables\Actions\Action::make('content')
                    ->label('查看正文')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (ConfigFile $record): string => $record->label() . ' 配置正文')
                    ->modalContent(fn (ConfigFile $record) => view('filament.resources.node-config-content', [
                        'config' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭'),
                Tables\Actions\Action::make('edit')
                    ->label('编辑正文')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => auth()->user()?->can('publish configs') ?? false)
                    ->fillForm(fn (ConfigFile $record): array => ['content_yaml' => $record->content_yaml])
                    ->form([
                        Forms\Components\Textarea::make('content_yaml')
                            ->label('配置正文（YAML）')
                            ->rows(18)
                            ->required()
                            ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px;']),
                        Forms\Components\TextInput::make('reason')->label('修改原因')->default('panel edit'),
                    ])
                    ->action(function (ConfigFile $record, array $data, ConfigFileService $files): void {
                        $updated = $files->updateContent($record, (string) $data['content_yaml'], auth()->user(), (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title($updated->label() . ' 正文已更新')
                            ->body('sha256 ' . $updated->shortHash())
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('set_default')
                    ->label('设为默认版本')
                    ->icon('heroicon-o-star')
                    ->color('success')
                    ->visible(fn (ConfigFile $record): bool => (! $record->is_default)
                        && (auth()->user()?->can('publish configs') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('设为默认版本：新建/采纳节点、以及下发时默认取用的就是它。')
                    ->form([
                        Forms\Components\TextInput::make('reason')->label('原因')->default('指定默认版本'),
                    ])
                    ->action(function (ConfigFile $record, array $data, ConfigFileService $files): void {
                        $current = $files->setDefault($record, auth()->user(), (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title($current->label() . ' 已设为默认版本')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** 右上角「创建版本」：填正文 → 生成新的一版（可选设为默认版本）。 */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_version')
                ->label('创建版本')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('publish configs') ?? false)
                ->modalHeading('创建配置版本文件')
                ->modalDescription('与节点无关的一份配置正文：填内容 → 生成新版本。想给节点用就去「节点 → 配置版本」下发。')
                ->form([
                    Forms\Components\Textarea::make('content_yaml')
                        ->label('配置正文（YAML）')
                        ->rows(18)
                        ->required()
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px;']),
                    Forms\Components\TextInput::make('note')->label('备注')->maxLength(200),
                    Forms\Components\Toggle::make('make_default')
                        ->label('设为默认版本')
                        ->helperText('全库只有一个默认版本，下发时默认取它。')
                        ->default(true),
                ])
                ->action(function (array $data, ConfigFileService $files): void {
                    $file = $files->create(
                        (string) $data['content_yaml'],
                        auth()->user(),
                        (string) ($data['note'] ?? ''),
                        (bool) ($data['make_default'] ?? true),
                    );

                    Notification::make()
                        ->title('已创建 ' . $file->label())
                        ->body(($file->is_default ? '已设为默认版本' : '未设为默认版本') . ' · sha256 ' . $file->shortHash())
                        ->success()
                        ->send();
                }),
        ];
    }
}
