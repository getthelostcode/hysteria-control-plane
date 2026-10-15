<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Setting;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = '系统';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = '面板设置';

    protected static ?string $title = '面板设置';

    protected static string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'brand_name' => Setting::get('brand_name', 'Hysteria Control Plane'),
            'brand_logo' => Setting::get('brand_logo'),
            'theme_preset' => Setting::get('theme_preset', 'ocean'),
            'theme_primary' => Setting::get('theme_primary', ''),
            'theme_mode' => Setting::get('theme_mode', 'system'),
            'heartbeat_interval' => Setting::get('heartbeat_interval', 30),
            'offline_threshold' => Setting::get('offline_threshold', 90),
            'metrics_retention_days' => Setting::get('metrics_retention_days', 30),
            'node_domain' => Setting::get('node_domain', 'example.com'),
            'panel_footer' => Setting::get('panel_footer', 'Hysteria2 Control Plane'),
            'default_config_template' => Setting::get('default_config_template', ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('品牌')->columns(2)->schema([
                    Forms\Components\TextInput::make('brand_name')->label('面板名称')->required(),
                    Forms\Components\TextInput::make('brand_logo')->label('Logo URL')->url(),
                    Forms\Components\TextInput::make('panel_footer')->label('页脚文案'),
                    Forms\Components\TextInput::make('node_domain')->label('节点主域名')->required(),
                ]),
                Forms\Components\Section::make('主题')->columns(2)->schema([
                    Forms\Components\Select::make('theme_preset')
                        ->label('配色预设')
                        ->options(Setting::PRESET_LABELS)
                        ->required()
                        ->helperText('ocean 蓝 / forest 绿 / sunset 橙'),
                    Forms\Components\ColorPicker::make('theme_primary')
                        ->label('自定义主色（留空则用预设）'),
                    Forms\Components\Select::make('theme_mode')
                        ->label('默认明暗模式')
                        ->options(['system' => '跟随系统', 'light' => '亮色', 'dark' => '暗色'])
                        ->required(),
                ]),
                Forms\Components\Section::make('运行参数')->columns(3)->schema([
                    Forms\Components\TextInput::make('heartbeat_interval')->label('心跳间隔（秒）')->numeric()->minValue(5)->required(),
                    Forms\Components\TextInput::make('offline_threshold')->label('离线判定（秒）')->numeric()->minValue(15)->required(),
                    Forms\Components\TextInput::make('metrics_retention_days')->label('指标保留（天）')->numeric()->minValue(1)->required(),
                ]),
                Forms\Components\Section::make('默认配置模板')->schema([
                    Forms\Components\Textarea::make('default_config_template')
                        ->label('Hysteria2 YAML 模板')
                        ->rows(16)
                        ->extraInputAttributes(['class' => 'font-mono text-xs'])
                        ->helperText('可用占位符：{{node_id}} {{node_name}} {{region}} {{node_domain}} {{listen_port}} {{stats_secret}} {{obfs_password}} {{cert_path}} {{key_path}} {{masquerade}}'),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::setMany($data);

        Notification::make()
            ->title('设置已保存')
            ->body('主题配色与运行参数立即生效。')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    /** @return array<int, Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('save')->label('保存')->submit('save'),
        ];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
