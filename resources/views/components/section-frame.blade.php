@props(['team', 'site', 'current', 'title', 'period' => null, 'choice' => null, 'lede' => null, 'section' => 'traffic'])

{{-- A tab of a section (Traffic, Security): the period bar, the tabs, the period line, its title, then its own content. --}}
<x-layouts.app :title="match ($section) { 'security' => __('Security'), 'ai' => __('AI & bots'), 'sales' => __('Leads & sales'), default => __('Traffic') }">
    @if ($choice !== null)
        <x-slot:toolbar>
            <x-period-switch :current="$choice" />
        </x-slot:toolbar>
    @endif

    <x-dynamic-component :component="$section.'-tabs'" :team="$team" :site="$site" :current="$current" />

    @if ($period !== null)
        <x-period-line :period="$period" />
    @endif

    <div class="mb-4">
        <h2 class="text-2xl font-bold tracking-tight">{{ $title }}</h2>
        @if ($lede)
            <p class="mt-1 max-w-[92ch] text-[13px] text-ink-2">{{ $lede }}</p>
        @endif
    </div>

    @if ($period !== null && ! $period->hasFigures())
        <x-card :title="__('No figures yet')" :description="__('A day is counted once it is over where the site is, then counted a second time by the audit. Its figures show here only when both counts agree.')">
            <p class="text-ink-2">{{ __('The first figures appear the day after the site sends its first log lines.') }}</p>
        </x-card>
    @else
        <div class="grid gap-5">
            {{ $slot }}
        </div>
    @endif
</x-layouts.app>
