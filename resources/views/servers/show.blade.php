<x-layouts.app :title="$server->name()" :lede="__('The server, as edgelog-ship --metrics reads it from /proc and df once a minute: how busy its processors are, its memory, load, disks and network. Each minute is kept :minutes days, each hour\'s averages and peaks :hours days.', ['minutes' => config('ingest.metrics_minute_days'), 'hours' => config('ingest.metrics_hour_days')])">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
        <x-crumb :href="route('teams.servers.index', $team)">{{ __('Servers') }}</x-crumb>
    </x-slot:trail>

    @can('delete', $server)
        <x-slot:actions>
            <form method="POST" action="{{ route('teams.servers.destroy', [$team, $server]) }}" onsubmit="return confirm(@js(__('Forget :server and its figures? Its sites stay; a shipper still sending figures makes it again.', ['server' => $server->name()])))">
                @csrf
                @method('DELETE')
                <x-button variant="danger" size="sm">{{ __('Forget the server') }}</x-button>
            </form>
        </x-slot:actions>
    @endcan

    <div class="grid gap-5">
        @can('update', $server)
            <form method="POST" action="{{ route('teams.servers.update', [$team, $server]) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @method('PUT')
                <x-input :label="__('Name')" name="name" :value="$server->name" :hint="$server->hostname === null ? __('Empty: the host of its first site.') : __('Empty: its host name, :hostname.', ['hostname' => $server->hostname])" />
                <x-button variant="secondary">{{ __('Rename') }}</x-button>
            </form>
        @endcan

        <x-card :title="trans_choice('The :count site it runs|The :count sites it runs', $server->sites->count())" flush>
            @forelse ($server->sites as $site)
                <a href="{{ route('teams.sites.show', [$team, $site]) }}" class="flex min-h-12 items-center gap-3 border-b border-grid px-4 py-2 no-underline last:border-b-0 hover:bg-sunk">
                    <x-tile :label="$site->name" :seed="$site->uuid" :src="$site->iconUrl($team)" />
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">{{ $site->name }}</span>
                        <span class="block truncate text-[13px] text-ink-3">{{ $site->url }}</span>
                    </span>
                    <x-alert-chips :alerts="$siteAlerts[$site->id] ?? []" class="ml-auto justify-end" />
                    <x-icon name="arrow-right" @class(['text-ink-3', 'ml-auto' => ($siteAlerts[$site->id] ?? []) === []]) />
                </a>
            @empty
                <p class="p-4 text-ink-2">{{ __('No site runs on it any more.') }}</p>
            @endforelse
        </x-card>

        @if ($range->latest() === null)
            <x-card :title="__('No figures from the server yet')">
                <p class="text-ink-2">{{ __('The first figures show here a minute after a shipper starts with --metrics.') }}</p>
            </x-card>
        @else
            <x-server-figures :range="$range" :timezone="$server->timezone()" />
        @endif
    </div>
</x-layouts.app>
