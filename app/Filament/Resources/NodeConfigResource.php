<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NodeConfigResource\Pages;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use App\Support\YamlDiff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class NodeConfigResource extends Resource
{
    protected static ?string $model = NodeConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = '配置管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '节点配置';

    protected static ?string $pluralModelLabel = '配置版本';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('node');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('node_ref')
                ->label('节点')
                ->options(fn (): array => Node::query()->orderBy('name')->pluck('name', 'node_id')->all())
                ->searchable()
                ->required()
                ->visibleOn('create')
                ->dehydrated(false),
            Forms\Components\Textarea::make('content_yaml')
                ->label('Hysteria2 配置 (YAML)')
                ->rows(20)
                ->required()
                ->extraInputAttributes(['class' => 'font-mono text-xs']),
            Forms\Components\TextInput::make('reason')
                ->label('变更原因')
                ->maxLength(500)
                ->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('node.name')->label('节点')->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_current')
                    ->label('默认下发')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip('节点会更新到这一版（可在行操作里改）')
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')->label('版本')->badge()->color('info')->sortable(),
                Tables\Columns\TextColumn::make('sha256')
                    ->label('sha256')
                    ->formatStateUsing(fn (NodeConfig $record): string => $record->shortHash())
                    ->copyable()
                    ->copyableState(fn (NodeConfig $record): string => $record->sha256)
                    ->tooltip(fn (NodeConfig $record): string => $record->sha256),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NodeConfig::STATUS_APPLIED => 'success',
                        NodeConfig::STATUS_FAILED => 'danger',
                        NodeConfig::STATUS_ROLLED_BACK => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('publisher.name')->label('发布人')->placeholder('-'),
                Tables\Columns\TextColumn::make('published_at')->label('发布时间')->since()->sortable(),
                Tables\Columns\TextColumn::make('applied_at')->label('生效时间')->since()->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        NodeConfig::STATUS_PUBLISHED => '已发布',
                        NodeConfig::STATUS_APPLIED => '已生效',
                        NodeConfig::STATUS_FAILED => '失败',
                        NodeConfig::STATUS_ROLLED_BACK => '已回滚',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('content')
                    ->label('查看正文')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (NodeConfig $record): string => 'v' . $record->version . ' 配置正文')
                    ->modalContent(fn (NodeConfig $record) => view('filament.resources.node-config-content', [
                        'config' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭'),
                Tables\Actions\Action::make('edit')
                    ->label('编辑正文')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false)
                    ->modalDescription('就地修改此版本的正文（sha256 会重算）。若改的是「默认下发」版本，节点下一次同步/轮询即取走新内容。')
                    ->fillForm(fn (NodeConfig $record): array => ['content_yaml' => $record->content_yaml])
                    ->form([
                        Forms\Components\Textarea::make('content_yaml')
                            ->label('配置正文（YAML）')
                            ->rows(18)
                            ->required()
                            ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px;']),
                        Forms\Components\TextInput::make('reason')->label('修改原因')->default('panel edit'),
                    ])
                    ->action(function (NodeConfig $record, array $data, ConfigService $configs): void {
                        $updated = $configs->updateContent($record, (string) $data['content_yaml'], Auth::user(), (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title('v' . $updated->version . ' 正文已更新')
                            ->body('sha256 ' . $updated->shortHash())
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('activate')
                    ->label('设为默认下发')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('success')
                    ->visible(fn (NodeConfig $record): bool => (! $record->is_current)
                        && (Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('把这一版设为该节点默认下发的版本：节点下一次同步/轮询就会取走它的内容（不新生成版本）。')
                    ->form([
                        Forms\Components\TextInput::make('reason')->label('原因')->default('指定下发版本'),
                    ])
                    ->action(function (NodeConfig $record, array $data, ConfigService $configs): void {
                        $current = $configs->setCurrent($record->node, $record->version, Auth::user(), (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title('v' . $current->version . ' 已设为默认下发')
                            ->body('节点下次同步即生效')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('rollback')
                    ->label('回滚到此版本')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('将基于此版本内容生成一个新的配置版本并下发给节点。')
                    ->visible(fn (): bool => Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false)
                    ->form([
                        Forms\Components\TextInput::make('reason')->label('回滚原因')->default('manual rollback'),
                    ])
                    ->action(function (NodeConfig $record, array $data, ConfigService $configs): void {
                        $new = $configs->rollback($record->node, $record->version, Auth::user(), (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title('已回滚，新版本 v' . $new->version)
                            ->body('内容取自 v' . $record->version . ' · sha256 ' . $new->shortHash())
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('diff')
                    ->label('对比')
                    ->icon('heroicon-o-code-bracket-square')
                    ->modalHeading(fn (NodeConfig $record): string => '版本 diff: v' . $record->version)
                    ->modalContent(fn (NodeConfig $record) => view('filament.resources.node-config-diff', [
                        'config' => $record,
                        'previous' => $record->previous(),
                        'diff' => YamlDiff::stats($record->previous()?->content_yaml ?? '', $record->content_yaml),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭'),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNodeConfigs::route('/'),
            'create' => Pages\CreateNodeConfig::route('/create'),
            'view' => Pages\ViewNodeConfig::route('/{record}'),
        ];
    }
}
