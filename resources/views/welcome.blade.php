<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        <header class="flex w-full justify-end p-6">
            @if (Route::has('login'))
                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        class="rounded-md border border-zinc-200 px-4 py-1.5 text-sm dark:border-zinc-700"
                    >
                        Dashboard
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="rounded-md border border-zinc-200 px-4 py-1.5 text-sm dark:border-zinc-700"
                    >
                        Log in
                    </a>
                @endauth
            @endif
        </header>

        <main class="flex flex-1 flex-col items-center justify-center gap-4 pb-16">
            <span class="flex size-20 items-center justify-center rounded-2xl bg-accent-content text-accent-foreground">
                <x-app-logo-icon class="size-11 text-white dark:text-black" />
            </span>
            <p class="text-2xl font-medium tracking-wide">AI.DENE</p>
        </main>
    </body>
</html>
