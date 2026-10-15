{{-- Panel colours come from the settings table, so switching the preset in
     面板设置 (or a custom hex) re-themes every component without a redeploy. --}}
<style id="cp-theme-vars">{!! \App\Support\Setting::cssVariables() !!}</style>

@php($cpDefaultMode = (string) \App\Support\Setting::get('theme_mode', 'system'))

{{-- Default light/dark mode from the settings table. A per-browser choice in
     Filament's own theme switcher (localStorage) always wins. --}}
<script>
    (function () {
        var fallback = @json($cpDefaultMode);
        try {
            if (! window.localStorage.getItem('theme')) {
                window.localStorage.setItem('theme', fallback);
            }
            var mode = window.localStorage.getItem('theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var dark = mode === 'dark' || (mode === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', dark);
        } catch (error) {
            // private mode / storage disabled: fall back to Filament's own handling
        }
    })();
</script>
