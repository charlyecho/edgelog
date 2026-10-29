@php
    use App\Analytics\ServerAlerts;
    use Illuminate\Support\Number;

    $share = fn (int $part, int $whole) => $whole > 0 ? $part / $whole * 100 : null;
    $percent = fn (?float $value) => $value === null ? '–' : Number::format($value, maxPrecision: 0).'%';
@endphp

<x-layouts.app :title="__('Servers')" :lede="__('The machines that run the sites of :team, as edgelog-ship --metrics reports them. A server shows up with the first figures its shipper sends; the sites whose shippers run on the same machine share it.', ['team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
    </x-slot:trail>

    @can('create', [\App\Models\Site::class, $team])
        <x-slot:actions>
            <x-button size="sm" :href="route('teams.sites.create', $team)"><x-icon name="plus" class="size-3.5" /> {{ __('Add a site') }}</x-button>
        </x-slot:actions>
    @endcan

    @if ($servers->isEmpty() && $unattached->isEmpty())
        <x-card :title="__('No server yet')" :description="__('A server shows up here a minute after a site\'s shipper starts with --metrics. The service written in Send the log, in each site\'s settings, already has it.')">
            <p class="text-ink-2">{{ __('No site yet.') }}</p>
        </x-card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($servers as $server)
                @php
                    $latest = $server->latestMinute;
                    $fullest = collect($latest?->disks ?? [])->map(fn (array $disk) => $share($disk['used'], $disk['used'] + $disk['free']))->filter(fn ($value) => $value !== null)->max();
                    $memory = $latest === null ? null : $share($latest->memoryUsed(), $latest->memory_total);
                @endphp
                {{-- The whole card opens the server; its sites, above that link, open each site. --}}
                <div class="relative rounded-[10px] border border-rule bg-surface p-4 shadow-card hover:border-gap">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 flex-none place-items-center rounded-[26%] border border-rule bg-sunk text-ink-2">
                            <x-icon name="server" class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <a href="{{ route('teams.servers.show', [$team, $server]) }}" class="block truncate font-bold no-underline after:absolute after:inset-0 after:rounded-[10px]">{{ $server->name() }}</a>
                            <span class="block truncate text-[13px] text-ink-3">
                                {{ $server->last_sample_at === null ? __('No figures yet') : __('Last sample :when', ['when' => $server->last_sample_at->diffForHumans()]) }}
                            </span>
                        </span>
                    </div>

                    <x-alert-chips :alerts="$alerts[$server->id] ?? []" class="mt-3" />

                    @if ($latest !== null)
                        <span class="mt-3 grid grid-cols-3 gap-2 text-xs text-ink-3">
                            @foreach ([[__('CPU'), $latest->cpu], [__('Memory'), $memory], [__('Fullest disk'), $fullest]] as [$label, $value])
                                <span>
                                    <span class="block">{{ $label }}</span>
                                    <span @class([
                                        'block text-base font-bold tabular-nums',
                                        'text-bad' => ServerAlerts::shareTone($value) === 'bad',
                                        'text-warn' => ServerAlerts::shareTone($value) === 'warn',
                                        'text-ink' => ServerAlerts::shareTone($value) === null,
                                    ])>{{ $percent($value) }}</span>
                                </span>
                            @endforeach
                        </span>
                    @endif

                    <div class="mt-3 grid border-t border-grid pt-1">
                        @forelse ($server->sites as $site)
                            <x-site-row :team="$team" :site="$site" :alerts="$siteAlerts[$site->id] ?? []" class="relative z-10" />
                        @empty
                            <span class="pt-2 text-xs text-ink-3">{{ __('No site runs on it any more.') }}</span>
                        @endforelse
                    </div>
                </div>
            @endforeach

            @if ($unattached->isNotEmpty())
                <div class="rounded-[10px] border border-dashed border-rule bg-surface p-4">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 flex-none place-items-center rounded-[26%] border border-dashed border-rule text-ink-3">
                            <x-icon name="globe" class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate font-bold">{{ __('No known server') }}</span>
                            <span class="block text-[13px] text-ink-3">{{ trans_choice(':count site whose shipper sends no figures yet.|:count sites whose shippers send no figures yet.', $unattached->count()) }}</span>
                        </span>
                    </div>

                    <div class="mt-3 grid border-t border-grid pt-1">
                        @foreach ($unattached as $site)
                            <x-site-row :team="$team" :site="$site" :alerts="$siteAlerts[$site->id] ?? []" />
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>
