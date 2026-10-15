<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\NodeConfigResource;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use App\Support\HysteriaConfigBuilder;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * 「配置管理 → 版本管理」：一次管理一台节点的全部配置版本。
 *
 * 设计要点（按使用需求定）：
 *   * 顶部选定节点，列表只显示它的版本 —— 所以每个版本只出现一次，也不需要节点列；
 *   * 列：版本（第一列、按先后顺序、可点进详情）｜状态｜发布时间｜最后修改时间｜生效时间；
 *     不显示 sha256；
 *   * 行操作只保留「设为默认下发」；查看/查看正文/编辑正文/回滚/对比都收进版本详情页
 *     （点版本那一列进去），列表因此很干净；
 *   * 右上角「创建版本」用于发布新版本。
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

    /** 顶部选定的节点（一次只管一台：版本不会重复出现）。 */
    public ?int $nodeId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view nodes') ?? false;
    }

    public function mount(): void
    {
        $requested = (int) request()->integer('node_id');

        $this->form->fill([
            'node_id' => $requested > 0 && Node::query()->whereKey($requested)->exists()
                ? $requested
                : Node::query()->orderBy('id')->value('id'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('node_id')
                    ->label('节点')
                    ->options(fn (): array => Node::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        $this->nodeId = (int) $state;
                        $this->resetTable();
                    })
                    ->required(),
            ])
            ->statePath('data');
    }

    public function getSubheading(): ?string
    {
        $node = Node::query()->find($this->selectedNodeId());

        if ($node === null) {
            return '还没有节点。先在「节点管理」里注册一台。';
        }

        return $node->name . ' 的配置版本（按先后顺序）；点「版本」看正文并编辑，行操作可指定默认下发版本。';
    }

    private function selectedNodeId(): int
    {
        return (int) ($this->data['node_id'] ?? $this->nodeId ?? 0);
    }

    public function table(Table $table): Table
    {
        return NodeConfigResource::table($table)
            ->query(NodeConfig::query()->where('node_id', $this->selectedNodeId()))
            ->columns([
                NodeConfigResource::linkedVersionColumn(),
                NodeConfigResource::statusColumn(),
                NodeConfigResource::publishedAtColumn(),
                NodeConfigResource::updatedAtColumn(),
                NodeConfigResource::appliedAtColumn(),
            ])
            ->defaultSort('version')
            ->paginationPageOptions([25, 50, 100])
            ->actions([
                NodeConfigResource::activateAction(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        NodeConfig::STATUS_PUBLISHED => '已发布',
                        NodeConfig::STATUS_APPLIED => '已生效',
                        NodeConfig::STATUS_FAILED => '失败',
                        NodeConfig::STATUS_ROLLED_BACK => '已回滚',
                    ]),
            ])
            ->headerActions([]);
    }

    /** 页面右上角「创建版本」：选节点 → 填正文（留空用面板模板）→ 是否设为默认下发。 */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_version')
                ->label('创建版本')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('publish configs') ?? false)
                ->modalHeading('创建配置版本')
                ->modalDescription('选节点 → 填正文（留空则用面板模板渲染）→ 是否设为默认下发。新版本会写审计与历史。')
                ->fillForm(fn (): array => ['node_id' => $this->selectedNodeId() ?: null, 'make_current' => true])
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
}
