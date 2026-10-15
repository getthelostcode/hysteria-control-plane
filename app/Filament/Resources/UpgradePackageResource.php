<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UpgradePackageResource\Pages;
use App\Models\UpgradePackage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UpgradePackageResource extends Resource
{
    protected static ?string $model = UpgradePackage::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = '运维操作';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = '升级包';

    protected static ?string $pluralModelLabel = '升级发布';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('版本信息')->columns(3)->schema([
                Forms\Components\TextInput::make('version')->label('版本号')->required()->maxLength(32),
                Forms\Components\Select::make('arch')->label('架构')
                    ->options(['amd64' => 'amd64', 'arm64' => 'arm64', 'arm' => 'arm', '386' => '386'])
                    ->default('amd64')->required(),
                Forms\Components\Select::make('channel')->label('频道')
                    ->options([
                        UpgradePackage::CHANNEL_STABLE => 'stable',
                        UpgradePackage::CHANNEL_BETA => 'beta',
                        UpgradePackage::CHANNEL_CANARY => 'canary',
                    ])->default(UpgradePackage::CHANNEL_STABLE)->required(),
            ]),
            Forms\Components\TextInput::make('url')->label('下载地址')->url()->required()->maxLength(512)->columnSpanFull(),
            Forms\Components\TextInput::make('sha256')->label('sha256')->required()->length(64)->rule('regex:/^[a-f0-9]{64}$/i'),
            Forms\Components\Textarea::make('signature')->label('ed25519 签名')->rows(3),
            Forms\Components\TextInput::make('rollout_percent')->label('灰度比例 (%)')->numeric()->minValue(0)->maxValue(100)->default(100),
            Forms\Components\DateTimePicker::make('released_at')->label('发布时间')->default(now()),
            Forms\Components\Toggle::make('is_active')->label('启用')->default(true),
            Forms\Components\Textarea::make('notes')->label('发布说明')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('released_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('版本')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('arch')->label('架构')->badge(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('频道')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        UpgradePackage::CHANNEL_BETA => 'warning',
                        UpgradePackage::CHANNEL_CANARY => 'danger',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('rollout_percent')->label('灰度')->suffix('%'),
                Tables\Columns\TextColumn::make('sha256')
                    ->label('sha256')
                    ->formatStateUsing(fn (UpgradePackage $record): string => $record->shortHash())
                    ->copyable()
                    ->copyableState(fn (UpgradePackage $record): string => (string) $record->sha256),
                Tables\Columns\IconColumn::make('signature')->label('已签名')->boolean()->getStateUsing(fn (UpgradePackage $record): bool => $record->signature !== null),
                Tables\Columns\ToggleColumn::make('is_active')->label('启用'),
                Tables\Columns\TextColumn::make('released_at')->label('发布时间')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->label('频道')->options([
                    UpgradePackage::CHANNEL_STABLE => 'stable',
                    UpgradePackage::CHANNEL_BETA => 'beta',
                    UpgradePackage::CHANNEL_CANARY => 'canary',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUpgradePackages::route('/'),
            'create' => Pages\CreateUpgradePackage::route('/create'),
            'view' => Pages\ViewUpgradePackage::route('/{record}'),
            'edit' => Pages\EditUpgradePackage::route('/{record}/edit'),
        ];
    }
}
