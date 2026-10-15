<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\NodeConfigResource;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use App\Support\HysteriaConfigBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
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

    /**
     * 页面右上角「创建版本」：选节点 → 填正文（留空用面板模板）→ 是否设为默认下发。
     *
     * 放在页面级（而不是表格级）：它就是「版本管理」这个页面的主操作。
     */
    protected function getHeaderActions(): array
    {
        return [
        Actions\Action::make('create_version')
            ->label('创建版本')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => auth()->user()?->can('publish configs') ?? false)
            ->modalHeading('创建配置版本')
            ->modalDescription('选节点 → 填正文（留空则用面板模板渲染）→ 是否设为默认下发。新版本会写审计与历史。')
            ->form([
                Forms\Components\Select::make('node_id')
                    ->label('节点')
                    ->options(fn (): array => Node::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Forms\Components\Textarea::make('content_yaml')
                    ->label('配置正文（YAML，留空用面板模板）')
                    ->rows(18)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px;']),
                Forms\Components\Toggle::make('make_current')
                    ->label('设为默认下发版本')
                    ->helperText('打开后节点会更新到这一版；关掉则只入历史（草稿）。')
                    ->default(true),
                Forms\Components\TextInput::make('reason')->label('变更原因')->maxLength(500),
            ])
            ->action(function (array $data, ConfigService $configs): void {
                $node = Node::query()->findOrFail($data['node_id']);

                $yaml = trim((string) ($data['content_yaml'] ?? ''));
                if ($yaml === '') {
                    $yaml = HysteriaConfigBuilder::render($node);
                }

                $config = $configs->publish(
                    $node,
                    $yaml,
                    auth()->user(),
                    (string) ($data['reason'] ?? ''),
                    ['channel' => 'filament'],
                    (bool) ($data['make_current'] ?? true),
                );

                Notification::make()
                    ->title('已创建 v' . $config->version . '（' . $node->name . '）')
                    ->body(($config->is_current ? '已设为默认下发' : '仅入历史（未设为默认）') . ' · sha256 ' . $config->shortHash())
                    ->success()
                    ->send();
            }),
        
        ];
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
            // 列：第一列是版本（按先后顺序）；不显示节点列；发布时间/最后修改时间要显示
            ->columns([
                NodeConfigResource::versionColumn(),
                NodeConfigResource::currentVersionColumn(),
                NodeConfigResource::statusColumn(),
                NodeConfigResource::hashColumn(),
                NodeConfigResource::publishedAtColumn(),
                NodeConfigResource::updatedAtColumn(),
                NodeConfigResource::appliedAtColumn(),
            ])
            ->defaultSort('version')
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
