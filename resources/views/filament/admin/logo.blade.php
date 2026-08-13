@php
    $home = filament()->getCurrentPanel()?->getUrl() ?? url('/');
@endphp
<a class="fi-logo flex items-center gap-2" href="{{ $home }}">
    <img
        class="h-8 w-auto dark:hidden"
        src="{{ asset('img/fynix_logo_dark.png') }}"
        alt="{{ setting('general.name', 'Fynix Cyber Audit') }}"
    >
    <img
        class="hidden h-8 w-auto dark:block"
        src="{{ asset('img/fynix_logo_white.png') }}"
        alt="{{ setting('general.name', 'Fynix Cyber Audit') }}"
    >
</a>
