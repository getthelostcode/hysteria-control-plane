import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Admin panel theme. Registered through ->viteTheme() in
                // App\Providers\Filament\AdminPanelProvider; it is a separate
                // bundle so the panel can be themed without touching app.css.
                'resources/css/filament/admin/theme.css',
                // Reverb/Echo client used by the panel (push path for command acks,
                // config publications and node status changes).
                'resources/js/echo.js',
            ],
            refresh: true,
        }),
    ],
});
