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
                        <a href="{{ route('career-input.index') }}" class="text-2xl font-bold tracking-tight">
                            Success
                        </a>
                        <button
                            type="button"
                            class="nav-hamburger"
                            aria-label="Open menu"
                            aria-expanded="false"
                            aria-controls="nav-menu-modal"
                            data-nav-trigger
                        >
                            {{-- Three-line hamburger drawn in SVG so the
                                 stroke color follows currentColor. --}}
                            <svg width="20" height="14" viewBox="0 0 20 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <line x1="1" y1="1" x2="19" y2="1"/>
                                <line x1="1" y1="7" x2="19" y2="7"/>
                                <line x1="1" y1="13" x2="19" y2="13"/>
                            </svg>
                        </button>
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

        {{-- Navigation modal. Lives at the document root so its
             backdrop covers the entire viewport including the card.
             Hidden by default; the .is-open class on .nav-modal-root
             toggles visibility. The inert attribute ensures focus and
             screen readers skip the modal contents when closed. --}}
        <div
            class="nav-modal-root"
            id="nav-menu-modal"
            data-nav-modal
            role="dialog"
            aria-modal="true"
            aria-labelledby="nav-modal-heading"
            inert
        >
            <div class="nav-modal-backdrop" data-nav-backdrop aria-hidden="true"></div>

            <button
                type="button"
                class="nav-modal-close"
                aria-label="Close menu"
                data-nav-close
            >
                {{-- × glyph drawn in SVG to control stroke and weight. --}}
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="5" y1="5" x2="19" y2="19"/>
                    <line x1="19" y1="5" x2="5" y2="19"/>
                </svg>
            </button>

            <div class="nav-modal-panel">
                <h2 id="nav-modal-heading" class="sr-only">Site navigation</h2>
                <nav class="nav-modal-links">
                    <a
                        href="{{ route('career-input.index') }}"
                        class="nav-modal-link {{ request()->routeIs('career-input.*') ? 'is-current' : '' }}"
                    >
                        Career Input
                    </a>
                    <a
                        href="{{ route('organizations.index') }}"
                        class="nav-modal-link {{ request()->routeIs('organizations.*') ? 'is-current' : '' }}"
                    >
                        Organizations
                    </a>
                    <a
                        href="https://github.com/wetfish/success"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="nav-modal-link"
                    >
                        GitHub
                    </a>
                </nav>
            </div>
        </div>

        {{-- Navigation modal controller. Plain DOM API, IIFE so we
             don't pollute the global scope. Handles toggle from the
             hamburger button, dismissal via backdrop click, close
             button, and Escape key. --}}
        <script>
            (function () {
                const root = document.querySelector('[data-nav-modal]');
                const trigger = document.querySelector('[data-nav-trigger]');
                const backdrop = document.querySelector('[data-nav-backdrop]');
                const closeBtn = document.querySelector('[data-nav-close]');

                if (!root || !trigger) return;

                function open() {
                    root.classList.add('is-open');
                    root.removeAttribute('inert');
                    trigger.setAttribute('aria-expanded', 'true');
                    document.body.style.overflow = 'hidden';
                }

                function close() {
                    root.classList.remove('is-open');
                    root.setAttribute('inert', '');
                    trigger.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                    trigger.focus();
                }

                trigger.addEventListener('click', () => {
                    if (root.classList.contains('is-open')) {
                        close();
                    } else {
                        open();
                    }
                });

                backdrop?.addEventListener('click', close);
                closeBtn?.addEventListener('click', close);

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && root.classList.contains('is-open')) {
                        close();
                    }
                });
            })();
        </script>
    </body>
</html>