<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollTokenResource\Pages;
use App\Models\EnrollToken;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EnrollTokenResource extends Resource
{
    protected static ?string $model = EnrollToken::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = '节点管理';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = '注册令牌';

    protected static ?string $pluralModelLabel = '注册令牌';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('note')->label('备注')->maxLength(255),
            Forms\Components\TextInput::make('max_uses')
                ->label('最大使用次数')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),
            Forms\Components\TextInput::make('expires_in_days')
                ->label('有效期（天）')
                ->numeric()
                ->minValue(1)
                ->maxValue(365)
                ->default(7)
                ->dehydrated(false),
            Forms\Components\TextInput::make('region_hint')->label('建议区域')->maxLength(64),
            Forms\Components\TagsInput::make('tags_hint')->label('建议标签'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('prefix')->label('令牌前缀')->badge()->searchable(),
                Tables\Columns\TextColumn::make('note')->label('备注')->limit(40),
                Tables\Columns\TextColumn::make('usage')
                    ->label('已用/上限')
                    ->state(fn (EnrollToken $record): string => $record->uses . ' / ' . $record->max_uses),
                Tables\Columns\TextColumn::make('region_hint')->label('区域')->placeholder('-'),
                Tables\Columns\TextColumn::make('expires_at')->label('过期时间')->since()->placeholder('永不过期'),
                Tables\Columns\TextColumn::make('is_usable')
                    ->label('状态')
                    ->badge()
                    ->state(fn (EnrollToken $record): string => $record->isUsable() ? '可用' : '不可用')
                    ->color(fn (EnrollToken $record): string => $record->isUsable() ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('creator.name')->label('创建人')->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')->label('创建时间')->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('作废')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EnrollToken $record): bool => $record->isUsable()
                        && (Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false))
                    ->action(function (EnrollToken $record): void {
                        $record->forceFill([
                            'expires_at' => now()->subSecond(),
                            'max_uses' => $record->uses,
                        ])->save();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollTokens::route('/'),
            'create' => Pages\CreateEnrollToken::route('/create'),
        ];
    }
}
