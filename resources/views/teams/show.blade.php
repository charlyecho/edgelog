<x-layouts.app :title="__('Sites')" :lede="__('The sites of :team. Every site belongs to exactly one team.', ['team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.show', $team)">{{ $team->name }}</x-crumb>
    </x-slot:trail>

    @can('create', [\App\Models\Site::class, $team])
        <x-slot:actions>
            <x-button size="sm" :href="route('teams.sites.create', $team)"><x-icon name="plus" class="size-3.5" /> {{ __('Add a site') }}</x-button>
        </x-slot:actions>
    @endcan

    @if ($sites->isEmpty())
        <x-card>
            <p class="text-ink-2">{{ __('No site yet.') }}</p>
        </x-card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($sites as $site)
                <a href="{{ route('teams.sites.show', [$team, $site]) }}" class="flex items-center gap-3 rounded-[10px] border border-rule bg-surface p-4 no-underline hover:border-gap">
                    <x-tile :label="$site->name" :seed="$site->uuid" size="size-9 text-lg" />
                    <span class="min-w-0">
                        <span class="block truncate font-bold">{{ $site->name }}</span>
                        <span class="block truncate text-[13px] text-ink-3">{{ $site->url }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.app>
