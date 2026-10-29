@php
    use App\Ingest\ShipperSetup;

    $latest = $range->latest();
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="server" :title="__('Server')"
    :lede="__('The site\'s server, as edgelog-ship --metrics reads it from /proc and df once a minute: how busy its processors are, its memory, load, disks and network. Each minute is kept :minutes days, each hour\'s averages and peaks :hours days. Sites whose shippers run on the same machine share one server, and its figures are kept once.', ['minutes' => config('ingest.metrics_minute_days'), 'hours' => config('ingest.metrics_hour_days')])">
    @if ($hiddenServer)
        <x-card :title="__('No access to this server')">
            <p class="text-ink-2">{{ __('The site runs on a server you were not given access to. Ask an admin of the team to grant it to you.') }}</p>
        </x-card>
    @elseif ($latest === null)
        <x-card :title="__('No figures from the server yet')" :description="__('The shipper sends them when it runs with --metrics. Nothing else to install: it reads /proc and df with awk, which Debian and Ubuntu have.')">
            <ol class="grid gap-4">
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">1.</span> {{ __('On a new server, follow Send the log in the site settings: the service it writes already has --metrics.') }}</p>
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">2.</span> {{ __('If the shipper already runs: take the new one, add --metrics to its service, and restart it.') }}</p>
                    <x-code :code="$setup->enableMetricsCommands()" />
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">3.</span> {{ __('To see what is sent, read one sample by hand:') }}</p>
                    <x-code :code="ShipperSetup::printMetricsCommand()" />
                </li>
            </ol>
            <x-slot:after>
                <p class="text-ink-2">{{ __('The first figures show here a minute after the shipper starts.') }}</p>
            </x-slot:after>
        </x-card>
    @else
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-ink-2">
            <x-icon name="server" class="size-4 text-ink-3" />
            {{ __('Runs on') }}
            <a href="{{ route('teams.servers.show', [$team, $server]) }}" class="font-semibold text-ink underline">{{ $server->name() }}</a>
            @if ($neighbours->isNotEmpty())
                {{ trans_choice('Its figures are shared with :count other site: :sites.|Its figures are shared with :count other sites: :sites.', $neighbours->count(), ['sites' => $neighbours->pluck('name')->join(', ')]) }}
            @endif
        </p>

        <x-server-figures :range="$range" :timezone="$site->timezone" />
    @endif
</x-section-frame>
