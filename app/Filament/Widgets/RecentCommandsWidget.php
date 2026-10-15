<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use App\Models\NodeCommand;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class RecentCommandsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '最近指令';

    public function table(Table $table): Table
    {
        return $table
            ->query(NodeCommand::query()->with('node')->latest('id')->limit(8))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('node.name')->label('节点'),
                Tables\Columns\TextColumn::make('type')->label('指令')->badge()->formatStateUsing(fn (NodeCommand $record): string => $record->typeLabel()),
                Tables\Columns\TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        NodeCommand::STATUS_PENDING => '待执行',
                        NodeCommand::STATUS_SENT => '已送达',
                        NodeCommand::STATUS_ACKED => '已完成',
                        NodeCommand::STATUS_FAILED => '失败',
                        default => '已过期',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        NodeCommand::STATUS_ACKED => 'success',
                        NodeCommand::STATUS_FAILED => 'danger',
                        NodeCommand::STATUS_SENT => 'info',
                        NodeCommand::STATUS_EXPIRED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('issued_at')->label('下发')->since(),
                Tables\Columns\TextColumn::make('acked_at')->label('回执')->since()->placeholder('-'),
            ]);
    }

    public static function canView(): bool
    {
        return Auth::user()?->can('viewAny', NodeCommand::class) ?? false;
    }
}
