<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NodeCommandResource\Pages;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class NodeCommandResource extends Resource
{
    protected static ?string $model = NodeCommand::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = '运维操作';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '节点指令';

    protected static ?string $pluralModelLabel = '指令队列';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['node', 'issuer']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('node_id')
                ->label('节点')
                ->relationship('node', 'name')
                ->searchable()
                ->required(),
            Forms\Components\Select::make('type')
                ->label('类型')
                ->options(NodeCommand::TYPES)
                ->required(),
            Forms\Components\KeyValue::make('payload')->label('参数'),
            Forms\Components\Select::make('status')
                ->label('状态')
                ->options(self::statusLabels())
                ->default(NodeCommand::STATUS_PENDING)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('command_id')
                    ->label('指令 ID')
                    ->formatStateUsing(fn (NodeCommand $record): string => substr($record->command_id, 0, 8))
                    ->copyable()
                    ->copyableState(fn (NodeCommand $record): string => $record->command_id)
                    ->tooltip(fn (NodeCommand $record): string => $record->command_id),
                Tables\Columns\TextColumn::make('node.name')->label('节点')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('类型')
                    ->badge()
                    ->formatStateUsing(fn (NodeCommand $record): string => $record->typeLabel()),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        NodeCommand::STATUS_ACKED => 'success',
                        NodeCommand::STATUS_FAILED => 'danger',
                        NodeCommand::STATUS_SENT => 'info',
                        NodeCommand::STATUS_EXPIRED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('issuer.name')->label('操作人')->placeholder('system'),
                Tables\Columns\TextColumn::make('issued_at')->label('下发时间')->since()->sortable(),
                Tables\Columns\TextColumn::make('acked_at')->label('回执时间')->since()->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('状态')->options(self::statusLabels()),
                Tables\Filters\SelectFilter::make('type')->label('类型')->options(NodeCommand::TYPES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->label('重新下发')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false)
                    ->action(function (NodeCommand $record, CommandService $commands): void {
                        $command = $commands->issue($record->node, $record->type, $record->payload ?? [], Auth::user());

                        Notification::make()
                            ->title('已重新下发')
                            ->body($command->command_id)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNodeCommands::route('/'),
            'create' => Pages\CreateNodeCommand::route('/create'),
            'view' => Pages\ViewNodeCommand::route('/{record}'),
            'edit' => Pages\EditNodeCommand::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function statusLabels(): array
    {
        return [
            NodeCommand::STATUS_PENDING => '待执行',
            NodeCommand::STATUS_SENT => '已送达',
            NodeCommand::STATUS_ACKED => '已完成',
            NodeCommand::STATUS_FAILED => '失败',
            NodeCommand::STATUS_EXPIRED => '已过期',
        ];
    }
}
