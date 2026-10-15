<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnrollTokenResource\Pages;

use App\Filament\Resources\EnrollTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEnrollTokens extends ListRecords
{
    protected static string $resource = EnrollTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // 明文只存哈希，签发后无法再查；把最近一次签发的明文留在会话里，
            // 让运维能重新打开并一键复制（而不是去手抄通知里的长串，然后抄成掩码）。
            Actions\Action::make('revealToken')
                ->label('查看刚签发的令牌')
                ->icon('heroicon-o-clipboard-document')
                ->color('gray')
                ->visible(fn (): bool => filled(session(EnrollTokenResource::PLAINTEXT_SESSION_KEY)))
                ->modalHeading('注册令牌明文')
                ->modalDescription('控制面只保存 SHA-256 哈希；这里展示的是本次会话中最近一次签发/重签的值，关闭浏览器后不再可用。')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('我已复制/记下')
                ->modalContent(fn () => view('filament.enroll-token-plaintext', [
                    'plain' => (string) session(EnrollTokenResource::PLAINTEXT_SESSION_KEY),
                    'prefix' => substr((string) session(EnrollTokenResource::PLAINTEXT_SESSION_KEY), 0, 12),
                ])),
        ];
    }
}
