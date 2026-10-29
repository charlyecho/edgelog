<x-layouts.app :title="__('Access of :name', ['name' => $member->name])" :lede="__(':name is a viewer of :team: they read the pages, never change anything. Choose what they may see.', ['name' => $member->name, 'team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
        <x-crumb :href="route('teams.edit', $team)">{{ __('Team') }}</x-crumb>
    </x-slot:trail>

    <form method="POST" action="{{ route('teams.members.access.update', [$team, $member]) }}" class="grid max-w-5xl gap-4">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <x-notice tone="bad">{{ $errors->first() }}</x-notice>
        @endif

        <x-card>
            <x-access-fields :team="$team" :sites="$sites" :servers="$servers" :limited="$membership->limited" :granted-sites="$grantedSites" :granted-servers="$grantedServers" />
        </x-card>

        <div class="flex items-center gap-3">
            <x-button>{{ __('Save') }}</x-button>
            <a href="{{ route('teams.edit', $team) }}" class="text-ink-2 underline hover:text-ink">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.app>
