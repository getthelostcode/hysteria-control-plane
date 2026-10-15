<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit">
                保存设置
            </x-filament::button>
            <span class="text-xs text-gray-500">
                配色预设与主色会立即应用到当前面板（写入 settings 表并注入 CSS 变量）。
            </span>
        </div>
    </x-filament-panels::form>
</x-filament-panels::page>
