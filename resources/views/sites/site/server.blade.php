@php
    use App\Analytics\Server\ServerRange;
    use App\Ingest\ShipperSetup;
    use Illuminate\Support\Number;

    $latest = $server->latest();
    $points = $server->points();
    $first = 'var(--group-people)';
    $second = 'var(--group-answer_engines)';
    $ranges = [
        '6h' => __('6 hours'),
        '24h' => __('24 hours'),
        '7d' => __('7 days'),
        '30d' => __('30 days'),
        '90d' => __('90 days'),
        '1y' => __('1 year'),
    ];
    $local = fn (\Carbon\CarbonInterface $time, string $format = 'H:i') => $time->setTimezone($site->timezone)->translatedFormat($format);
    $share = fn (int $part, int $whole) => $whole > 0 ? $part / $whole * 100 : 0;
    $percent = fn (float $value) => Number::format($value, maxPrecision: 1).'%';
    $tone = fn (?float $value) => match (true) {
        $value === null => null,
        $value >= 90 => 'bad',
        $value >= 75 => 'warn',
        default => null,
    };
    $withPeaks = $server->step() > 60;
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="server" :title="__('Server')"
    :lede="__('The site\'s server, as edgelog-ship --metrics reads it from /proc and df once a minute: how busy its processors are, its memory, load, disks and network. Each minute is kept :minutes days, each hour\'s averages and peaks :hours days.', ['minutes' => config('ingest.metrics_minute_days'), 'hours' => config('ingest.metrics_hour_days')])">
    @if ($latest === null)
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
        <div class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-ink-2" data-live-refresh="60">
            <x-segmented name="range" :current="$server->range" :default="ServerRange::DEFAULT" :options="$ranges" :aria-label="__('Range')" />
            <p class="flex flex-wrap items-center gap-x-2">
                {{ __('Last sample at :time (:timezone).', ['time' => $local($latest->minute, 'H:i'), 'timezone' => $site->timezone]) }}
                <span data-live-state>{{ __('Refreshed every minute.') }}</span>
                <button type="button" class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-rule bg-surface px-3 font-semibold text-ink hover:border-ink-3" data-live-pause data-pause="{{ __('Pause') }}" data-resume="{{ __('Resume') }}" data-paused="{{ __('Paused.') }}" data-running="{{ __('Refreshed every minute.') }}">
                    {{ __('Pause') }}
                </button>
            </p>
        </div>

        @if ($server->isSilent())
            <x-notice tone="warn" :title="__('The server has gone quiet')">
                {{ __('No figures since :when. Is edgelog-ship still running, with --metrics? journalctl -u edgelog-ship says why a minute was not kept.', ['when' => $latest->minute->diffForHumans()]) }}
            </x-notice>
        @endif

        @php
            $memory = $share($latest->memoryUsed(), $latest->memory_total);
            $fullest = collect($latest->disks)->map(fn (array $disk) => [...$disk, 'share' => $share($disk['used'], $disk['used'] + $disk['free'])])->sortByDesc('share')->values();
        @endphp

        <x-stat-row :stats="[
            [__('Processors'), $latest->cpu === null ? '–' : $percent($latest->cpu), trans_choice('Busy share of :count core, over the last minute.|Busy share of all :count cores, over the last minute.', $latest->cores), $tone($latest->cpu)],
            [__('Load'), Number::format($latest->load1, precision: 2), __(':five over 5 minutes, :fifteen over 15. Above :cores (the cores), work waits for a processor.', ['five' => Number::format($latest->load5, precision: 2), 'fifteen' => Number::format($latest->load15, precision: 2), 'cores' => $latest->cores]), $latest->load5 > $latest->cores ? 'bad' : null],
            [__('Memory'), $percent($memory), __(':used of :total in use; the cache the system can give back is not counted.', ['used' => Number::fileSize($latest->memoryUsed(), maxPrecision: 1), 'total' => Number::fileSize($latest->memory_total, maxPrecision: 1)]), $tone($memory)],
            [__('Swap'), $latest->swap_total === 0 ? __('None') : $percent($share($latest->swapUsed(), $latest->swap_total)), $latest->swap_total === 0 ? __('The server has no swap.') : __(':used of :total in use.', ['used' => Number::fileSize($latest->swapUsed(), maxPrecision: 1), 'total' => Number::fileSize($latest->swap_total, maxPrecision: 1)])],
            [__('Fullest disk'), $fullest->isEmpty() ? '–' : $percent($fullest[0]['share']), $fullest->isEmpty() ? __('No disk was read.') : __(':mount, :free free.', ['mount' => $fullest[0]['mount'], 'free' => Number::fileSize($fullest[0]['free'], maxPrecision: 1)]), $tone($fullest[0]['share'] ?? null)],
            [__('Up for'), \Carbon\CarbonInterval::seconds($latest->uptime)->cascade()->forHumans(['parts' => 2, 'short' => true]), __('Since the server last started.')],
        ]" />

        <div class="grid gap-4 lg:grid-cols-2">
            <x-charts.server-chart :title="__('Processors')" :points="$points" unit="percent" :step="$server->step()" :timezone="$site->timezone" :fixed-ceiling="100" :lines="array_values(array_filter([
                ['key' => 'cpu', 'label' => $withPeaks ? __('Busy, average') : __('Busy'), 'color' => $first, 'area' => true],
                $withPeaks ? ['key' => 'cpu_peak', 'label' => __('Busy, peak'), 'color' => $first, 'dashed' => true] : null,
                ['key' => 'iowait', 'label' => __('Waiting on disk'), 'color' => $second],
            ]))" />

            <x-charts.server-chart :title="__('Memory')" :points="$points" unit="percent" :step="$server->step()" :timezone="$site->timezone" :fixed-ceiling="100" :lines="array_values(array_filter([
                ['key' => 'memory', 'label' => $withPeaks ? __('Memory, average') : __('Memory'), 'color' => $first, 'area' => true],
                $withPeaks ? ['key' => 'memory_peak', 'label' => __('Memory, peak'), 'color' => $first, 'dashed' => true] : null,
                $latest->swap_total > 0 ? ['key' => 'swap', 'label' => __('Swap'), 'color' => $second] : null,
            ]))" />

            <x-charts.server-chart :title="__('Load')" :points="$points" unit="load" :step="$server->step()" :timezone="$site->timezone" :guide="['value' => (float) $server->cores(), 'label' => trans_choice(':count core|:count cores', $server->cores())]" :lines="array_values(array_filter([
                ['key' => 'load', 'label' => $withPeaks ? __('Load, average') : __('Load'), 'color' => $first, 'area' => true],
                $withPeaks ? ['key' => 'load_peak', 'label' => __('Load, peak'), 'color' => $first, 'dashed' => true] : null,
            ]))" />

            <x-charts.server-chart :title="__('Network')" :points="$points" unit="bytes" :step="$server->step()" :timezone="$site->timezone" :lines="[
                ['key' => 'network_in', 'label' => __('Received'), 'color' => $first],
                ['key' => 'network_out', 'label' => __('Sent'), 'color' => $second],
            ]" />

            <x-charts.server-chart :title="__('Disk reads and writes')" :points="$points" unit="bytes" :step="$server->step()" :timezone="$site->timezone" :lines="[
                ['key' => 'disk_read', 'label' => __('Read'), 'color' => $first],
                ['key' => 'disk_write', 'label' => __('Written'), 'color' => $second],
            ]" />

            <x-rows-table :title="__('Disks')" :unit="__('Each file system, as df saw it in the last sample, fullest first.')"
                :columns="[[__('Mounted on'), 'left'], [__('Used'), 'right'], [__('Free'), 'right'], [__('Size'), 'right'], [__('Full'), 'right']]"
                :rows="$fullest->map(fn (array $disk) => [
                    ['label' => $disk['mount'], 'mono' => true],
                    Number::fileSize($disk['used'], maxPrecision: 1),
                    Number::fileSize($disk['free'], maxPrecision: 1),
                    Number::fileSize($disk['size'], maxPrecision: 1),
                    ['label' => $percent($disk['share']), 'tone' => $tone($disk['share'])],
                ])->all()" :empty="__('No disk was read.')" />
        </div>

        <p class="text-xs text-ink-3">
            @if ($server->readsMinutes())
                {{ __('Each point is :minutes of samples. Busy shares and bytes a second are since the sample before, so the first one after the shipper starts has none.', ['minutes' => trans_choice(':count minute|:count minutes', intdiv($server->step(), 60))]) }}
            @else
                {{ __('Each point is :hours of the hours summed up: the average, and the peak dashed. The hour going on shows once it is over.', ['hours' => trans_choice(':count hour|:count hours', intdiv($server->step(), 3600))]) }}
            @endif
        </p>
    @endif
</x-section-frame>
