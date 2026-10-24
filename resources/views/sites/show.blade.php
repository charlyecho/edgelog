<x-layouts.app :title="__('Overview')">
    <x-slot:trail>
        <x-crumb :href="route('teams.show', $team)">{{ $team->name }}</x-crumb>
        <x-crumb :href="route('teams.sites.show', [$team, $site])">{{ $site->name }}</x-crumb>
    </x-slot:trail>

    <x-slot:actions>
        <x-button variant="secondary" size="sm" :href="$site->url" target="_blank" rel="noopener noreferrer">{{ $site->host() }} <x-icon name="external" class="size-3.5" /></x-button>
    </x-slot:actions>

    <x-card :title="__('Visitors, pages read, by day')" :description="__('The figures will show once the site sends its first data.')">
        <p class="py-10 text-center text-ink-3">{{ __('No data yet.') }}</p>
    </x-card>
</x-layouts.app>
