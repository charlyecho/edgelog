<x-layouts.app :title="__('Sites')" :lede="__('The sites of :team. Every site belongs to exactly one team.', ['team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
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
                @php($days = $visitors[$site->id] ?? [])
                @php($known = array_filter($days, fn ($value) => $value !== null))
                <a href="{{ route('teams.sites.show', [$team, $site]) }}" class="block rounded-[10px] border border-rule bg-surface p-4 no-underline shadow-card hover:border-gap">
                    <span class="flex items-center gap-3">
                        <x-tile :label="$site->name" :seed="$site->uuid" size="size-9 text-lg" />
                        <span class="min-w-0">
                            <span class="block truncate font-bold">{{ $site->name }}</span>
                            <span class="block truncate text-[13px] text-ink-3">{{ $site->url }}</span>
                        </span>
                    </span>

                    @if (count($known) > 1)
                        <x-sparkline :values="$days" color="var(--series-visitors)" :width="320" class="mt-3 h-auto max-w-none" />
                        <span class="mt-1 flex justify-between text-xs text-ink-3">
                            <span><span class="font-semibold text-ink tabular-nums">{{ number_format(array_sum($known)) }}</span> {{ __('visitors, last 30 days') }}</span>
                            @if (count($known) < count($days))
                                <span>{{ __(':count of :days days', ['count' => count($known), 'days' => count($days)]) }}</span>
                            @endif
                        </span>
                    @else
                        <span class="mt-3 block text-xs text-ink-3">{{ __('No figures for the last 30 days yet.') }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.app>
