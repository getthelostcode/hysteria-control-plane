<x-filament-panels::page>
    <x-filament-panels::form wire:submit.prevent>
        {{ $this->form }}
    </x-filament-panels::form>

    {{ $this->table }}
</x-filament-panels::page>
