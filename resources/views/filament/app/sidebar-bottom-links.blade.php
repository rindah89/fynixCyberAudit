@php
    $sidebarCollapsible = filament()->isSidebarCollapsibleOnDesktop();

    $links = [
        [
            'label' => 'Settings',
            'url' => '/admin/settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'external' => false,
            'permissions' => ['Manage Users', 'View Audit Log', 'Manage Permissions', 'Configure Authentication'],
        ],
        [
            'label' => 'Help',
            'url' => 'https://github.com/rindah89/fynixCyberAudit',
            'icon' => 'heroicon-o-question-mark-circle',
            'external' => true,
            'permissions' => null,
        ],
    ];
@endphp

<div class="flex justify-center px-4 pt-4">
    <div class="w-[90%] border-t border-gray-600"></div>
</div>
<ul class="fi-sidebar-nav-groups -mx-2 flex flex-col gap-y-1 px-4 py-4">
    @foreach ($links as $link)
        @if ($link['permissions'])
            @canany($link['permissions'])
                <x-filament-panels::sidebar.item
                    :icon="$link['icon']"
                    :url="$link['url']"
                    :should-open-url-in-new-tab="$link['external']"
                >
                    {{ $link['label'] }}
                </x-filament-panels::sidebar.item>
            @endcanany
        @else
            <x-filament-panels::sidebar.item
                :icon="$link['icon']"
                :url="$link['url']"
                :should-open-url-in-new-tab="$link['external']"
            >
                {{ $link['label'] }}
            </x-filament-panels::sidebar.item>
        @endif
    @endforeach
</ul> 