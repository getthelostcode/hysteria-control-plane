<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">
                保存
            </x-filament::button>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                保存只改「默认版本」指针，不会新建版本；节点下次同步（hysteria-agent config sync）取它。
            </span>
        </div>
    </x-filament-panels::form>

    {{ $this->table }}
</x-filament-panels::page>
