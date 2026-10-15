<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NodeResource\Pages;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\CommandService;
use App\Services\ConfigService;
use App\Support\HysteriaConfigBuilder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class NodeResource extends Resource
{
    protected static ?string $model = Node::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = '节点管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '节点';

    protected static ?string $pluralModelLabel = '节点';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['latestMetric', 'latestConfig']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基本信息')->columns(3)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('节点名称')->required()->maxLength(80),
                Forms\Components\TextInput::make('node_id')
                    ->label('Node ID')->disabled()->dehydrated(false)->placeholder('注册后自动生成'),
                Forms\Components\Select::make('status')
                    ->label('状态')
                    ->options(array_combine(Node::STATUSES, Node::STATUSES))
                    ->default(Node::STATUS_ONLINE)
                    ->required(),
                Forms\Components\TextInput::make('ip')
                ->label('公网 IP')
                ->maxLength(45)
                ->helperText('节点对外展示/排障用的公网地址。留空则等 agent 下一次上报自动填充。'),
                Forms\Components\TextInput::make('region')->label('区域')->maxLength(64),
                Forms\Components\TagsInput::make('tags')->label('标签')->columnSpanFull(),
            ]),
            Forms\Components\Section::make('版本')->columns(2)->schema([
                Forms\Components\TextInput::make('version')->label('Hysteria 版本')->disabled()->dehydrated(false),
                Forms\Components\TextInput::make('agent_version')->label('Agent 版本')->disabled()->dehydrated(false),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('节点')
                    ->description(fn (Node $record): string => (string) $record->node_id)
                    ->searchable(['name', 'node_id'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (Node $record): string => $record->isOnline() ? 'online' : $record->status)
                    ->color(fn (Node $record): string => match (true) {
                        $record->isOnline() => 'success',
                        $record->status === Node::STATUS_ERROR => 'danger',
                        $record->status === Node::STATUS_DISABLED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('region')->label('区域')->badge()->sortable()->searchable(),
                Tables\Columns\TextColumn::make('tags')->label('标签')->badge()->separator(','),
                Tables\Columns\TextColumn::make('ip')
                    ->label('公网 IP')
                    ->copyable()
                    ->placeholder('未知')
                    ->tooltip(fn (Node $record): ?string => ($record->meta['ip_source'] ?? null) === 'agent'
                        ? '节点自报的出口公网地址'
                        : (($record->meta['ip_source'] ?? null) === 'proxy' ? '由代理链（X-Forwarded-For）推导' : '控制面观测到的对端地址'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latestMetric.cpu')
                    ->label('CPU')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 1)
                    ->color(fn (?string $state): string => (float) $state > 85 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('latestMetric.mem')
                    ->label('内存')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 1),
                Tables\Columns\TextColumn::make('latestMetric.users')->label('用户')->numeric(),
                Tables\Columns\TextColumn::make('latestConfig.version')->label('配置版本')->badge()->default('-'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('最后心跳')
                    ->since()
                    ->sortable()
                    ->color(fn (Node $record): string => $record->isOnline() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('version')->label('Hysteria')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('meta.protocol')
                    ->label('通道')
                    ->badge()
                    ->placeholder('native')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options(array_combine(Node::STATUSES, Node::STATUSES)),
                Tables\Filters\SelectFilter::make('region')
                    ->label('区域')
                    ->options(fn (): array => Node::query()->whereNotNull('region')->distinct()->pluck('region', 'region')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('issueCommand')
                    ->label('下发指令')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (): bool => Auth::user()?->can('issueCommand', new Node()) ?? false)
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('指令类型')
                            ->options(self::commandTypeOptions())
                            ->searchable()
                            ->default(NodeCommand::TYPE_RESTART)
                            ->required(),
                        Forms\Components\KeyValue::make('payload')->label('参数')->reorderable(),
                    ])
                    ->action(function (Node $record, array $data, CommandService $commands): void {
                        $command = $commands->issue($record, $data['type'], $data['payload'] ?? [], Auth::user());

                        Notification::make()
                            ->title('指令已下发')
                            ->body(sprintf('%s → %s (%s)', $record->name, $command->typeLabel(), $command->command_id))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('publishConfig')
                    ->label('发布配置')
                    ->icon('heroicon-o-document-arrow-up')
                    ->visible(fn (): bool => Auth::user()?->can('publishConfig', new Node()) ?? false)
                    ->form([
                        Forms\Components\Textarea::make('content_yaml')
                            ->label('Hysteria2 配置 (YAML)')
                            ->rows(18)
                            ->required()
                            ->extraInputAttributes(['class' => 'font-mono text-xs'])
                            ->default(fn (Node $record): string => HysteriaConfigBuilder::render($record)),
                        Forms\Components\TextInput::make('reason')->label('变更原因')->maxLength(500),
                    ])
                    ->action(function (Node $record, array $data, ConfigService $configs): void {
                        $config = $configs->publish($record, $data['content_yaml'], Auth::user(), $data['reason'] ?? '');

                        Notification::make()
                            ->title('配置已发布 v' . $config->version)
                            ->body('sha256 ' . $config->shortHash() . ' - 节点将在下次心跳/WS 推送时热重载')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkCommand')
                    ->label('批量下发指令')
                    ->icon('heroicon-o-bolt')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('指令类型')
                            ->options(self::commandTypeOptions())
                            ->searchable()
                            ->default(NodeCommand::TYPE_RESTART)
                            ->required(),
                    ])
                    ->action(function ($records, array $data, CommandService $commands): void {
                        $issued = $commands->issueBulk($records, $data['type'], [], Auth::user());

                        Notification::make()
                            ->title(sprintf('已向 %d 个节点下发指令', $issued->count()))
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * Command types for the panel dropdown: types a non-manager may not issue
     * are labelled so nobody wastes a click (the API/service still enforces it).
     *
     * @return array<string, string>
     */
    public static function commandTypeOptions(): array
    {
        $isManager = Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;

        return collect(NodeCommand::TYPES)
            ->map(static fn (string $label, string $type): string => NodeCommand::isDangerousType($type) && ! $isManager
                ? $label . ' · 仅管理员'
                : $label)
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNodes::route('/'),
            'create' => Pages\CreateNode::route('/create'),
            'view' => Pages\ViewNode::route('/{record}'),
            'edit' => Pages\EditNode::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'node_id', 'ip', 'region'];
    }
}
