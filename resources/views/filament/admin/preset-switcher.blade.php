@php
    $presetCss = \App\Support\Setting::presetsAsCssVars();
@endphp

<div
    x-data="{
        current: localStorage.getItem('cp-preset') || null,
        apply(preset) {
            const root = document.documentElement;
            const vars = {{ \Illuminate\Support\Js::from($presetCss) }};
            if (! preset || ! vars[preset]) {
                this.current = null;
                localStorage.removeItem('cp-preset');
                Object.entries(vars[{{ \Illuminate\Support\Js::from((string) \App\Support\Setting::get('theme_preset', 'ocean')) }}] || {})
                    .forEach(([key, value]) => root.style.setProperty(key, value));
                return;
            }
            this.current = preset;
            localStorage.setItem('cp-preset', preset);
            Object.entries(vars[preset]).forEach(([key, value]) => root.style.setProperty(key, value));
        },
        init() { if (this.current) this.apply(this.current); },
    }"
    class="cp-preset-switcher flex items-center gap-1"
>
    <span class="text-xs text-gray-500">主题</span>
    @foreach (array_keys(\App\Support\Setting::PRESETS) as $preset)
        <button
            type="button"
            x-on:click="apply('{{ $preset }}')"
            :class="current === '{{ $preset }}' ? 'ring-2 ring-offset-1' : ''"
            class="cp-preset-dot cp-preset-{{ $preset }} h-4 w-4 rounded-full border border-black/10"
            title="{{ \App\Support\Setting::PRESET_LABELS[$preset] }}"
        ></button>
    @endforeach
</div>
