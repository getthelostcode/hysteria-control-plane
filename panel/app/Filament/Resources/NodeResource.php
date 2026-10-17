<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NodeResource\Pages;
use App\Models\Node;
use App\Models\NodeTrafficDaily;
use App\Models\Provider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NodeResource extends Resource
{
    protected static ?string $model = Node::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = '节点';

    protected static ?string $modelLabel = '节点';

    protected static ?string $pluralModelLabel = '节点';

    protected static ?string $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')->label('节点名称')->maxLength(120),
                Forms\Components\Select::make('status')
                    ->label('状态')
                    ->options(['active' => '启用', 'disabled' => '禁用'])
                    ->required(),
                Forms\Components\TextInput::make('ip')->label('IP')->disabled(),
                Forms\Components\TextInput::make('traffic_port')->label('trafficStats 端口')->disabled(),
            ])->columns(2),
        ]);
    }

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
                Tables\Columns\TextColumn::make('provider.code')
                    ->label('服务商识别码')
                    ->searchable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('status')
                    ->label('运行状态')
                    ->badge()
                    ->formatStateUsing(fn (Node $record): string => $record->runtimeStatusLabel())
                    ->color(fn (Node $record): string => $record->runtimeStatusColor()),
                Tables\Columns\TextColumn::make('traffic_port')
                    ->label('traffic 端口')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('version')
                    ->label('版本')
                    ->placeholder('—')
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
                Tables\Filters\SelectFilter::make('provider_id')
                    ->label('服务商')
                    ->options(fn (): array => Provider::query()->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('启用状态')
                    ->options(['active' => '启用', 'disabled' => '禁用']),
                Tables\Filters\Filter::make('online')
                    ->label('只看在线')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', Node::STATUS_ACTIVE)
                        ->where('last_seen_at', '>=', now()->subSeconds(config('hysteria.node_online_ttl', 90)))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('接入信息')->schema([
                Infolists\Components\TextEntry::make('ip')->label('公网 IP')->copyable(),
                Infolists\Components\TextEntry::make('hostname')->label('主机名')->placeholder('—'),
                Infolists\Components\TextEntry::make('provider.name')->label('服务商'),
                Infolists\Components\TextEntry::make('provider.code')->label('运营商识别码')->badge()->copyable(),
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
            ])->columns(3),

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
            'edit' => Pages\EditNode::route('/{record}/edit'),
        ];
    }
}
