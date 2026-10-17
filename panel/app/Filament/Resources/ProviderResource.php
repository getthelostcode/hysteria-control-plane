<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderResource\Pages;
use App\Models\Provider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = '服务商';

    protected static ?string $modelLabel = '服务商';

    protected static ?string $pluralModelLabel = '服务商';

    protected static ?string $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基本信息')->schema([
                Forms\Components\TextInput::make('code')
                    ->label('运营商识别码')
                    ->required()
                    ->minLength(4)
                    ->maxLength(64)
                    ->regex('/^[A-Za-z0-9._-]+$/')
                    ->unique(ignoreRecord: true)
                    ->helperText('同时作为节点 obfs salamander 密码，客户端需一致；改完已注册节点要重跑 install.sh'),
                Forms\Components\TextInput::make('name')
                    ->label('名称')
                    ->required()
                    ->maxLength(120),
                Forms\Components\TextInput::make('contact')
                    ->label('联系方式')
                    ->maxLength(190),
                Forms\Components\Select::make('status')
                    ->label('状态')
                    ->options(['active' => '正常', 'suspended' => '停用'])
                    ->default('active')
                    ->required()
                    ->helperText('停用后该服务商节点无法注册、后台账号无法登录'),
                Forms\Components\Textarea::make('notes')
                    ->label('备注')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('识别码')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('名称')
                    ->searchable(),
                Tables\Columns\TextColumn::make('contact')
                    ->label('联系方式')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nodes_count')
                    ->counts('nodes')
                    ->label('节点数')
                    ->badge(),
                Tables\Columns\TextColumn::make('provider_users_count')
                    ->counts('providerUsers')
                    ->label('后台账号')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? '正常' : '停用')
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('创建时间')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'create' => Pages\CreateProvider::route('/create'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }
}
