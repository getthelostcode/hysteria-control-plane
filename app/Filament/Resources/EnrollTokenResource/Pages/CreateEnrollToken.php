<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnrollTokenResource\Pages;

use App\Filament\Resources\EnrollTokenResource;
use App\Models\EnrollToken;
use App\Support\AuditLogger;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The plaintext token is shown exactly once: only its SHA-256 hash is stored.
 */
class CreateEnrollToken extends CreateRecord
{
    protected static string $resource = EnrollTokenResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        [$token, $plain] = EnrollToken::issue([
            'note' => $data['note'] ?? null,
            'max_uses' => (int) ($data['max_uses'] ?? 1),
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 7)),
            'region_hint' => $data['region_hint'] ?? null,
            'tags_hint' => $data['tags_hint'] ?? null,
        ], auth()->id());

        AuditLogger::log('enroll_token.created', $token, [
            'prefix' => $token->prefix,
            'max_uses' => $token->max_uses,
        ], auth()->user()?->name);

        Notification::make()
            ->title('注册令牌（仅显示一次）')
            ->body($plain)
            ->persistent()
            ->success()
            ->send();

        return $token;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
