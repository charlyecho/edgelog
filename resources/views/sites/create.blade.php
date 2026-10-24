<x-layouts.app :title="__('Add a site')" :lede="__('The site will belong to :team.', ['team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.show', $team)">{{ $team->name }}</x-crumb>
    </x-slot:trail>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('teams.sites.store', $team) }}" class="grid gap-3">
            @csrf
            <x-input :label="__('URL')" name="url" placeholder="https://example.com" required autofocus :hint="__('Only the address of the site is kept: https://example.com/blog becomes https://example.com.')" />
            <x-input :label="__('Name (optional)')" name="name" :hint="__('By default, the host name of the URL.')" />
            <div class="flex items-center gap-2">
                <x-button>{{ __('Add the site') }}</x-button>
                <x-button variant="secondary" :href="route('teams.show', $team)">{{ __('Cancel') }}</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
