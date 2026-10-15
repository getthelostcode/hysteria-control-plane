<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NodeConfigResource\Pages;
use App\Models\Node;
use App\Models\ConfigFile;
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
            Forms\Components\Select::make('config_file_id')
                ->label('从版本文件载入')
                ->options(fn (): array => ConfigFile::query()->orderByDesc('version')->get()
                    ->mapWithKeys(fn (ConfigFile $file): array => [
                        $file->id => $file->label() . ($file->is_default ? '（默认版本）' : '') . ($file->note !== '' ? ' · ' . $file->note : ''),
                    ])->all())
                ->live()
                ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                    $file = ConfigFile::query()->find($state);

                    if ($file !== null) {
                        $set('content_yaml', $file->content_yaml);
                    }
                })
                ->dehydrated(false)
                ->visibleOn('create')
                ->helperText('选一份「配置版本文件」会把正文填进来，仍可继续编辑。'),
            Forms\Components\Textarea::make('content_yaml')
                ->label('Hysteria2 配置 (YAML)')
                ->rows(20)
                ->required()
                ->extraInputAttributes(['class' => 'font-mono text-xs']),
            Forms\Components\TextInput::make('reason')
                ->label('变更原因')
                ->maxLength(500)
                ->dehydrated(false),
            Forms\Components\Toggle::make('make_current')
                ->label('发布后设为默认下发版本')
                ->helperText('节点会更新到这一版。关掉则只入历史（草稿），稍后在「版本管理」里显式指定。')
                ->default(true),
        ]);
    }


    // ---------------------------------------------------------------- 列定义
    // 抽成静态方法：NodeConfigResource 的列表与「版本管理」页共用（两处不漂移）。

    public static function nodeColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('node.name')->label('节点')->searchable()->sortable();
    }

    /** 默认版本：点开关即切换（走 ConfigService，节点下次同步取走）。 */
    public static function currentVersionColumn(): Tables\Columns\ToggleColumn
    {
        return Tables\Columns\ToggleColumn::make('is_current')
            ->label('默认版本')
            ->onIcon('heroicon-m-check-circle')
            ->offIcon('heroicon-m-minus-small')
            ->onColor('success')
            ->offColor('gray')
            ->tooltip('打开 = 设为该节点的默认下发版本（节点会更新到这一版）')
            ->disabled(fn (): bool => ! (Auth::user()?->can('publish configs') ?? false))
            ->updateStateUsing(function (NodeConfig $record, mixed $state): mixed {
                if (! $state) {
                    // 一台节点必须有且只有一版默认下发：关掉只能是切换，不能清空
                    Notification::make()
                        ->title('每台节点必须有且只有一版默认下发')
                        ->body('想换版本就直接打开目标那一版（或用行操作「设为默认下发」）。')
                        ->warning()
                        ->send();

                    return true;
                }

                app(ConfigService::class)->setCurrent($record->node, $record->version, Auth::user(), '面板切换默认版本');

                Notification::make()
                    ->title('已把 v' . $record->version . ' 设为默认下发')
                    ->body('节点 ' . ($record->node?->name ?? '-') . ' 下一次同步/轮询即取走')
                    ->success()
                    ->send();

                return true;
            })
            ->sortable();
    }

    /** 版本列（带链接）：点进去就是详情页（改正文 / 回滚 / 对比都在那里）。 */
    public static function linkedVersionColumn(): Tables\Columns\TextColumn
    {
        return self::versionColumn()
            ->url(fn (NodeConfig $record): string => self::getUrl('view', ['record' => $record]))
            ->tooltip('点开查看 / 编辑正文、回滚、对比');
    }

    /** 「设为默认下发」行操作（版本管理页与资源列表共用）。 */
    public static function activateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('activate')
            ->label('设为默认下发')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('success')
            ->visible(fn (NodeConfig $record): bool => (! $record->is_current)
                && (Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false))
            ->requiresConfirmation()
            ->modalDescription('把这一版设为该节点默认下发的版本：节点下一次同步/轮询就会取走它的内容。')
            ->form([
                Forms\Components\TextInput::make('reason')->label('原因')->default('指定下发版本'),
            ])
            ->action(function (NodeConfig $record, array $data, ConfigService $configs): void {
                $current = $configs->setCurrent($record->node, $record->version, Auth::user(), (string) ($data['reason'] ?? ''));

                Notification::make()
                    ->title('v' . $current->version . ' 已设为默认下发')
                    ->success()
                    ->send();
            });
    }

    public static function versionColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('version')
            ->label('版本')
            ->formatStateUsing(fn (NodeConfig $record): string => 'v' . $record->version)
            ->badge()
            ->color('info')
            ->sortable();
    }

    public static function hashColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('sha256')
            ->label('sha256')
            ->formatStateUsing(fn (NodeConfig $record): string => $record->shortHash())
            ->copyable()
            ->copyableState(fn (NodeConfig $record): string => $record->sha256)
            ->tooltip(fn (NodeConfig $record): string => $record->sha256);
    }

    public static function statusColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('status')
            ->label('状态')
            ->badge()
            ->color(fn (string $state): string => match ($state) {
                NodeConfig::STATUS_APPLIED => 'success',
                NodeConfig::STATUS_FAILED => 'danger',
                NodeConfig::STATUS_ROLLED_BACK => 'warning',
                default => 'info',
            });
    }

    public static function actorColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('publisher.name')->label('发布人')->placeholder('-');
    }

    public static function publishedAtColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('published_at')->label('发布时间')->since()->sortable()
            ->tooltip(fn (NodeConfig $record): ?string => $record->published_at?->toDateTimeString());
    }

    public static function updatedAtColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('updated_at')->label('最后修改时间')->since()->sortable()
            ->tooltip(fn (NodeConfig $record): ?string => $record->updated_at?->toDateTimeString());
    }

    public static function appliedAtColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('applied_at')->label('生效时间')->since()->placeholder('-')
            ->tooltip(fn (NodeConfig $record): ?string => $record->applied_at?->toDateTimeString());
    }

    public static function createdAtColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('created_at')->label('创建时间')->since()->sortable()
            ->toggleable(isToggledHiddenByDefault: true)
            ->tooltip(fn (NodeConfig $record): ?string => $record->created_at?->toDateTimeString());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('id', 'desc')
            ->columns([
                self::nodeColumn(),
                self::currentVersionColumn(),
                self::versionColumn(),
                self::hashColumn(),
                self::statusColumn(),
                self::actorColumn(),
                self::publishedAtColumn(),
                self::updatedAtColumn(),
                self::appliedAtColumn(),
                self::createdAtColumn(),
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
                self::activateAction(),
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
