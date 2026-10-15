<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\NodeConfigResource;
use App\Models\Node;
use App\Models\NodeConfig;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * 「配置管理 → 版本管理」：所有节点、所有配置版本的总表。
 *
 * 与 NodeConfigResource 的列表共用同一份列与行操作（直接复用它的 table()），
 * 额外提供按节点筛选 —— 这样「哪台节点现在下发的是哪一版」「某版能不能改」一眼就能看全。
 *
 * 能力：
 *   * 看全部版本（跨节点）＋ 按节点/状态筛选＋「只看默认下发」；
 *   * 「默认下发」列直接标出每台节点当前生效的那一版；
 *   * 行操作：查看正文 / 编辑正文 / 设为默认下发 / 回滚到此版本 / 对比。
 */
class ConfigVersions extends Page implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationGroup = '配置管理';

    protected static ?string $navigationLabel = '版本管理';

    protected static ?string $title = '配置版本管理';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.config-versions';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view nodes') ?? false;
    }

    public function getSubheading(): ?string
    {
        $node = $this->activeNodeLabel();

        return $node === null
            ? '所有节点的配置版本。每台节点的「默认下发」版本就是节点的更新目标，可随时改。'
            : '当前筛选：' . $node;
    }

    private function activeNodeLabel(): ?string
    {
        $nodeId = $this->tableFilters['node']['value'] ?? null;

        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        return Node::query()->whereKey($nodeId)->value('name');
    }

    public function table(Table $table): Table
    {
        // 复用资源里的列与行操作（单一来源，避免两处漂移）
        return NodeConfigResource::table($table)
            ->query(NodeConfig::query()->with('node'))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->filters([
                Tables\Filters\SelectFilter::make('node')
                    ->label('节点')
                    ->options(fn (): array => Node::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        NodeConfig::STATUS_PUBLISHED => '已发布',
                        NodeConfig::STATUS_APPLIED => '已生效',
                        NodeConfig::STATUS_FAILED => '失败',
                        NodeConfig::STATUS_ROLLED_BACK => '已回滚',
                    ]),
                Tables\Filters\TernaryFilter::make('is_current')
                    ->label('默认下发')
                    ->placeholder('全部')
                    ->trueLabel('只看默认下发版本')
                    ->falseLabel('只看非默认版本'),
            ])
            ->headerActions([]);
    }
}
