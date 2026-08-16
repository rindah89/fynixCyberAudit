<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Overview Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-filament::section>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-primary-100 dark:bg-primary-900 rounded-lg">
                        <x-filament::icon
                            icon="heroicon-o-code-bracket"
                            class="h-6 w-6 text-primary-600 dark:text-primary-400"
                        />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">{{ $totalRoutes }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">API Endpoints</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-success-100 dark:bg-success-900 rounded-lg">
                        <x-filament::icon
                            icon="heroicon-o-shield-check"
                            class="h-6 w-6 text-success-600 dark:text-success-400"
                        />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">Sanctum</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Authentication</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-warning-100 dark:bg-warning-900 rounded-lg">
                        <x-filament::icon
                            icon="heroicon-o-lock-closed"
                            class="h-6 w-6 text-warning-600 dark:text-warning-400"
                        />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">60/min</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Rate Limit</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Quick Start --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-rocket-launch" class="h-5 w-5" />
                    Quick Start
                </div>
            </x-slot>

            <div class="space-y-4">
                <div>
                    <h3 class="text-lg font-semibold mb-2">1. Generate API Token</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Navigate to your <a href="/app/me" class="text-primary-600 hover:underline">Profile Settings</a> and generate a new API token using Sanctum.
                    </p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-2">2. Make API Request</h3>
                    <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                        <pre class="text-sm text-gray-100"><code>curl -X GET "{{ url('/api/standards') }}" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"</code></pre>
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-2">3. View Full Documentation</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Scroll down for complete API reference or
                        <a href="{{ asset('API_DOCUMENTATION.md') }}" download class="text-primary-600 hover:underline">
                            download the documentation
                        </a>.
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Available Resources --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-rectangle-stack" class="h-5 w-5" />
                    Available Resources
                </div>
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($routesByResource as $resource => $routes)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-primary-500 transition">
                        <h4 class="font-semibold text-lg mb-2 capitalize flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-folder" class="h-4 w-4 text-primary-500" />
                            {{ str_replace('-', ' ', $resource) }}
                        </h4>
                        <div class="space-y-1 text-sm">
                            @foreach($routes as $route)
                                @php
                                    $methods = explode('|', $route['method']);
                                    $mainMethod = collect($methods)->first(fn($m) => in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']));
                                    $badgeColor = match($mainMethod) {
                                        'GET' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                        'POST' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                        'PUT', 'PATCH' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                        'DELETE' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
                                    };
                                @endphp
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium {{ $badgeColor }}">
                                        {{ $mainMethod }}
                                    </span>
                                    <code class="text-xs text-gray-600 dark:text-gray-400">{{ $route['uri'] }}</code>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Full Documentation --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                    Complete Documentation
                </div>
            </x-slot>

            <div class="prose prose-sm dark:prose-invert max-w-none">
                {!! $documentationHtml !!}
            </div>
        </x-filament::section>
    </div>

    <style nonce="{{ Vite::cspNonce() }}">
        /* Enhanced code block styling */
        .prose pre {
            @apply bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto;
        }

        .prose code {
            @apply bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-sm;
        }

        .prose pre code {
            @apply bg-transparent p-0;
        }

        /* Table styling */
        .prose table {
            @apply w-full border-collapse;
        }

        .prose th {
            @apply bg-gray-100 dark:bg-gray-800 font-semibold text-left px-4 py-2;
        }

        .prose td {
            @apply border-t border-gray-200 dark:border-gray-700 px-4 py-2;
        }

        /* Link styling */
        .prose a {
            @apply text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300;
        }

        /* Heading styling */
        .prose h2 {
            @apply text-2xl font-bold mt-8 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2;
        }

        .prose h3 {
            @apply text-xl font-semibold mt-6 mb-3;
        }

        .prose h4 {
            @apply text-lg font-semibold mt-4 mb-2;
        }
    </style>
</x-filament-panels::page>
