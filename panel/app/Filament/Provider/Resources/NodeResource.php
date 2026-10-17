<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Resources\NodeResource\Pages;
use App\Models\Node;
use App\Models\NodeTrafficDaily;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * 服务商视角的节点列表：Filament 多租户会自动按当前 provider 收窄，
 * 这里不需要再手写 where('provider_id', ...)。
 */
class NodeResource extends Resource
{
    protected static ?string $model = Node::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = '我的节点';

    protected static ?string $modelLabel = '节点';

    protected static ?string $pluralModelLabel = '节点';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('名称')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (Node $record): string => $record->runtimeStatusLabel())
                    ->color(fn (Node $record): string => $record->runtimeStatusColor()),
                Tables\Columns\TextColumn::make('traffic_port')
                    ->label('traffic 端口')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('version')
                    ->label('版本')
                    ->placeholder('未上报')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('最后心跳')
                    ->since()
                    ->placeholder('从未')
                    ->sortable(),
                Tables\Columns\TextColumn::make('traffic_today')
                    ->label('今日流量')
                    ->state(fn (Node $record): string => NodeTrafficDaily::human($record->trafficTodayBytes())),
            ])
            ->filters([
                Tables\Filters\Filter::make('online')
                    ->label('只看在线')
                    ->query(fn ($query) => $query
                        ->where('status', Node::STATUS_ACTIVE)
                        ->where('last_seen_at', '>=', now()->subSeconds(config('hysteria.node_online_ttl', 90)))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),

                Tables\Actions\Action::make('disable')
                    ->label('禁用')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('禁用该节点？')
                    ->modalDescription('禁用后节点的心跳会被拒绝（403），面板保留历史流量。')
                    ->visible(fn (Node $record): bool => ! $record->isDisabled())
                    ->action(function (Node $record): void {
                        $record->update(['status' => Node::STATUS_DISABLED]);
                        Notification::make()->warning()->title('节点已禁用')->send();
                    }),

                Tables\Actions\Action::make('enable')
                    ->label('启用')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Node $record): bool => $record->isDisabled())
                    ->action(function (Node $record): void {
                        $record->update(['status' => Node::STATUS_ACTIVE]);
                        Notification::make()->success()->title('节点已启用')->send();
                    }),

                Tables\Actions\Action::make('rotateSecret')
                    ->label('重置 secret')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('重新生成 trafficStats secret？')
                    ->modalDescription('节点上的 config.yaml 需要同步新 secret（或重跑 install.sh），否则流量上报会 401。')
                    ->action(function (Node $record): void {
                        $secret = bin2hex(random_bytes(24));
                        $record->update(['traffic_secret' => $secret]);

                        Notification::make()
                            ->warning()
                            ->title('secret 已重置')
                            ->body("新 secret：{$secret}\n请在节点上同步后再启用流量上报。")
                            ->persistent()
                            ->send();
                    }),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('接入信息')->schema([
                Infolists\Components\TextEntry::make('ip')->label('公网 IP')->copyable(),
                Infolists\Components\TextEntry::make('hostname')->label('主机名')->placeholder('—'),
                Infolists\Components\TextEntry::make('obfs_password')->label('运营商识别码（obfs 密码）')->copyable()->badge()->color('info'),
                Infolists\Components\TextEntry::make('traffic_port')->label('trafficStats 端口')->copyable(),
                Infolists\Components\TextEntry::make('traffic_secret')->label('trafficStats secret')
                    ->state(fn (Node $record): string => $record->traffic_secret)
                    ->copyable()
                    ->badge()
                    ->color('gray'),
            ])->columns(3),

            Infolists\Components\Section::make('运行状态')->schema([
                Infolists\Components\TextEntry::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (Node $record): string => $record->runtimeStatusLabel())
                    ->color(fn (Node $record): string => $record->runtimeStatusColor()),
                Infolists\Components\TextEntry::make('version')->label('版本')->placeholder('未上报'),
                Infolists\Components\TextEntry::make('last_seen_at')->label('最后心跳')->since()->placeholder('从未'),
                Infolists\Components\TextEntry::make('traffic_today')
                    ->label('今日流量')
                    ->state(fn (Node $record): string => NodeTrafficDaily::human($record->trafficTodayBytes())),
            ])->columns(4),

            Infolists\Components\Section::make('客户端配置片段')->schema([
                Infolists\Components\TextEntry::make('client_yaml')
                    ->hiddenLabel()
                    ->state(fn (Node $record): string => $record->clientConfigYaml())
                    ->copyable()
                    ->fontFamily('mono'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNodes::route('/'),
            'view' => Pages\ViewNode::route('/{record}'),
        ];
    }
}
