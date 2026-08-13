import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    safelist: [
        'ml-4',
        'ml-8',
        'block',
        'hidden',
        'text-sm',
        'text-lg',
        'font-medium',
        'font-semibold',
        'font-bold',
        '[&_.fi-btn-icon]:animate-spin',
    ],
}
