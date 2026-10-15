import preset from '../../../../vendor/filament/filament/tailwind.config.preset';

export default {
    presets: [preset],
    content: [
        // Panel PHP classes (widgets, resources, pages)
        './app/Filament/**/*.php',
        // Panel blade views (widget views, custom pages, diff renderer, ...)
        './resources/views/filament/**/*.blade.php',
        './resources/views/**/*.blade.php',
        // Filament itself
        './vendor/filament/**/*.blade.php',
    ],
};
