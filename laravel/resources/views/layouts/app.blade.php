<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Success')</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased min-h-screen">
        <div class="min-h-screen flex flex-col py-8 px-4 sm:px-6">
            <div
                class="flex-1 w-full max-w-5xl mx-auto rounded-xl border flex flex-col overflow-hidden"
                style="background: var(--color-surface-card); border-color: var(--color-surface-card-border);"
            >
                <header class="border-b" style="border-color: var(--color-divider);">
                    <div class="px-6 py-4 flex items-center justify-between">
                        <a href="{{ route('organizations.index') }}" class="text-2xl font-bold tracking-tight">
                            Success
                        </a>
                        <nav class="flex items-center gap-6 text-sm">
                            <a href="{{ route('organizations.index') }}" class="link-subtle">
                                Organizations
                            </a>
                        </nav>
                    </div>
                </header>

                <main class="flex-1 px-6 py-10">
                    @if (session('status'))
                        <div class="status-banner mb-6">
                            {{ session('status') }}
                        </div>
                    @endif

                    @yield('content')
                </main>

                <footer class="border-t px-6 py-4" style="border-color: var(--color-divider);">
                    <p class="text-xs" style="color: var(--color-text-muted);">
                        Success — career lifecycle tool
                    </p>
                </footer>
            </div>
        </div>
    </body>
</html>