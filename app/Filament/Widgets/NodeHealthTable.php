<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class NodeHealthTable extends BaseWidget
{
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '节点健康状态';

    public function table(Table $table): Table
    {
        return $table
            ->query(Node::query()->with('latestMetric')->where('status', '!=', Node::STATUS_DISABLED)->orderBy('name'))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('节点')->weight('semibold'),
                Tables\Columns\TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn (Node $record): string => $record->isOnline() ? '在线' : '离线')
                    ->color(fn (Node $record): string => $record->isOnline() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('latestMetric.cpu')->label('CPU')->suffix('%')->numeric(decimalPlaces: 1),
                Tables\Columns\TextColumn::make('latestMetric.mem')->label('内存')->suffix('%')->numeric(decimalPlaces: 1),
                Tables\Columns\TextColumn::make('latestMetric.conn_count')->label('连接')->numeric(),
                Tables\Columns\TextColumn::make('latestMetric.users')->label('用户')->numeric(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('最后心跳')->since(),
            ]);
    }

    public static function canView(): bool
    {
        return Auth::user()?->can('viewAny', Node::class) ?? false;
    }
}
