@props(['title', 'lede' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-page px-4 py-6 font-sans text-ink antialiased">
    <main class="w-full max-w-[400px] rounded-[14px] border border-rule bg-surface px-6 pt-6 pb-5 shadow-card">
        <a href="{{ url('/') }}" class="mb-4 flex items-center gap-2.5 text-base font-bold tracking-tight no-underline">
            <x-logo />
            <span>{{ config('app.name') }}</span>
        </a>

        <h1 class="mb-1 text-xl leading-tight font-bold tracking-tight">{{ $title }}</h1>
        @if ($lede)
            <p class="mb-4 text-[13.5px] text-ink-2">{{ $lede }}</p>
        @else
            <div class="mb-4"></div>
        @endif

        <x-flash />

        {{ $slot }}
    </main>
</body>
</html>
