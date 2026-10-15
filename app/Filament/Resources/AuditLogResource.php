<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = '系统';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = '审计日志';

    protected static ?string $pluralModelLabel = '审计日志';

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('时间')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('actor')->label('操作者')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('action')->label('动作')->badge()->searchable()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'delete'), str_contains($state, 'revoked') => 'danger',
                        str_contains($state, 'publish'), str_contains($state, 'issued') => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('target_type')->label('对象')->placeholder('-'),
                Tables\Columns\TextColumn::make('target')->label('对象 ID')->placeholder('-'),
                Tables\Columns\TextColumn::make('ip')->label('IP')->copyable()->placeholder('-'),
                Tables\Columns\TextColumn::make('meta')
                    ->label('详情')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state)
                    ->limit(60)
                    ->tooltip(fn ($state): string => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $state),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('动作')
                    ->options(fn (): array => AuditLog::query()->distinct()->pluck('action', 'action')->all()),
                Tables\Filters\SelectFilter::make('target_type')->label('对象类型')
                    ->options(fn (): array => AuditLog::query()->whereNotNull('target_type')->distinct()->pluck('target_type', 'target_type')->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return Auth::user()?->hasRole('super-admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
