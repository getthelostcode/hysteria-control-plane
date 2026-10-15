<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollTokenResource\Pages;
use App\Models\EnrollToken;
use App\Support\AuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

    /** 会话键：暂存本次会话最近一次签发/重签的明文，供「查看刚签发的令牌」重新打开。 */
    public const PLAINTEXT_SESSION_KEY = 'enroll_token.plaintext';

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
                    ->state(fn (EnrollToken $record): string => $record->statusLabel())
                    ->color(fn (EnrollToken $record): string => match ($record->statusLabel()) {
                        '可用' => 'success',
                        '已用尽' => 'warning',
                        '已过期' => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('creator.name')->label('创建人')->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')->label('创建时间')->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('reissue')
                    ->label('重新签发')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('重新签发注册令牌')
                    ->modalDescription('作废当前令牌并签发一个新的（备注/次数/区域不变）。新明文只在创建时显示一次；'
                        . '控制面只保存 SHA-256 哈希，明文丢失后无法找回，只能重签。')
                    ->visible(fn (EnrollToken $record): bool => $record->canBeManagedBy(Auth::user()))
                    ->action(function (EnrollToken $record): void {
                        $days = $record->expires_at !== null && $record->expires_at->isFuture()
                            ? max(1, (int) ceil(now()->diffInHours($record->expires_at) / 24))
                            : 7;

                        $record->revoke();

                        [$fresh, $plain] = EnrollToken::issue([
                            'note' => $record->note,
                            'max_uses' => max(1, (int) $record->max_uses),
                            'expires_at' => now()->addDays($days),
                            'region_hint' => $record->region_hint,
                            'tags_hint' => $record->tags_hint,
                        ], Auth::id());

                        AuditLogger::log('enroll_token.reissued', $fresh, [
                            'revoked_id' => $record->id,
                            'prefix' => $fresh->prefix,
                        ], Auth::user()?->name);

                        static::plaintextNotification($fresh, $plain, $record->id)->send();
                    }),
                Tables\Actions\Action::make('revoke')
                    ->label('作废')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EnrollToken $record): bool => ! $record->isRevoked()
                        && (Auth::user()?->hasAnyRole(['super-admin', 'admin', 'operator']) ?? false))
                    ->action(fn (EnrollToken $record) => $record->revoke()),
            ])
            ->bulkActions([]);
    }

    /**
     * 签发/重签后的明文展示。
     *
     * 曾经有运维在面板列表上看到 `enr_GmCVlx2W…`（哈希方案下只能显示掩码）就手抄去注册，
     * 拿到一句「enroll_token 无效、已使用或已过期」，而库里那条令牌其实未使用、未过期 —— 排查很久。
     *
     * 这里的两条约束都是踩出来的：
     * 1. 通知正文会被 `Str::sanitizeHtml()` 洗掉**所有属性**（`x-on:click`/`onclick` 都没了），
     *    所以正文只放明文文本，一键复制按钮放在列表页「查看刚签发的令牌」的 modalContent 里；
     * 2. 明文同时写入会话，便于运维在关闭通知后还能重新打开复制（服务端仍只存哈希）。
     */
    public static function plaintextNotification(EnrollToken $token, string $plain, ?int $revokedId = null): Notification
    {
        $head = $revokedId === null
            ? '注册令牌（明文仅显示一次）'
            : "新注册令牌（已作废 #{$revokedId}，明文仅显示一次）";

        session()->put(self::PLAINTEXT_SESSION_KEY, $plain);

        return Notification::make()
            ->title($head)
            ->body(sprintf(
                '完整令牌（%d 字符，形如 <code>enr_</code> + 40 位随机字符）：<br><code>%s</code><br>'
                . '列表里的 %s… 是**掩码**，用它注册一定失败；请用列表页右上角「查看刚签发的令牌」一键复制'
                . '（控制面只存 SHA-256 哈希，明文丢了只能重新签发）。',
                strlen($plain), e($plain), e($token->prefix),
            ))
            ->persistent()
            ->success();
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
