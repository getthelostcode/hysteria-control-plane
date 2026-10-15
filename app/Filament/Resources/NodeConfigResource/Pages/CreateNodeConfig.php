<?php

declare(strict_types=1);

namespace App\Filament\Resources\NodeConfigResource\Pages;

use App\Filament\Resources\NodeConfigResource;
use App\Models\Node;
use App\Services\ConfigService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Config versions are never inserted directly: publishing always goes through
 * ConfigService so the version counter, audit trail and push job stay consistent.
 */
class CreateNodeConfig extends CreateRecord
{
    protected static string $resource = NodeConfigResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $node = Node::query()->where('node_id', $data['node_ref'])->firstOrFail();

        $config = app(ConfigService::class)->publish(
            $node,
            (string) $data['content_yaml'],
            auth()->user(),
            (string) ($data['reason'] ?? ''),
            ['channel' => 'filament'],
            (bool) ($data['make_current'] ?? true),
        );

        Notification::make()
            ->title('配置已发布 v' . $config->version)
            ->body('节点 ' . $node->name . ' · sha256 ' . $config->shortHash())
            ->success()
            ->send();

        return $config;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
