<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NodeLogResource\Pages;
use App\Models\NodeLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NodeLogResource extends Resource
{
    protected static ?string $model = NodeLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = '运维操作';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = '节点日志';

    protected static ?string $pluralModelLabel = '节点日志';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('node');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('recorded_at')->label('时间')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('node.name')->label('节点')->searchable(),
                Tables\Columns\TextColumn::make('level')
                    ->label('级别')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'error', 'critical' => 'danger',
                        'warning', 'notice' => 'warning',
                        'diagnostic' => 'info',
                        'debug' => 'gray',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('message')->label('内容')->wrap()->limit(120)->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('级别')->options(array_combine(NodeLog::LEVELS, NodeLog::LEVELS)),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNodeLogs::route('/'),
        ];
    }
}
