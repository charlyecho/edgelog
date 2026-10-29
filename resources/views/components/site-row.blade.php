@props(['team', 'site', 'alerts' => []])

{{-- A site on its own line, opening its overview, with what is wrong with it. --}}
<a href="{{ route('teams.sites.show', [$team, $site]) }}" {{ $attributes->class('-mx-2 grid gap-1.5 rounded-lg px-2 py-2 text-ink no-underline hover:bg-sunk') }}>
    <span class="flex min-w-0 items-center gap-2">
        <x-tile :label="$site->name" :seed="$site->uuid" :src="$site->iconUrl($team)" size="size-5 text-[10px]" />
        <span class="truncate text-sm font-semibold">{{ $site->name }}</span>
    </span>
    <x-alert-chips :alerts="$alerts" class="pl-7" />
</a>
