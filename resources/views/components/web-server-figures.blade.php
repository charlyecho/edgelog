@props(['range', 'timezone'])

@php
    use App\Analytics\Server\ServerRange;
    use App\Ingest\ShipperSetup;
    use Illuminate\Support\Number;

    $latest = $range->latest();
    $points = $range->points();
    $first = 'var(--group-people)';
    $second = 'var(--group-answer_engines)';
    $withPeaks = $range->step() > 60;
    $readsPhp = $range->has('fpm_active');
    $readsNginx = $range->has('nginx_connections');
    $count = fn (int|float|null $value) => $value === null ? '–' : Number::format($value, maxPrecision: 1);
    $perSecond = fn (?float $value) => $value === null ? '–' : __(':count/s', ['count' => Number::format($value, maxPrecision: 2)]);
    $queuePeak = $range->peak('fpm_queue');
    $saturated = $range->total('fpm_saturated');
    $slow = $range->total('fpm_slow');
    $pools = $range->pools();
    $poolColors = ['var(--group-people)', 'var(--group-answer_engines)', 'var(--group-search_engines)', 'var(--group-bots)', 'var(--series-revenue)', 'var(--series-pages)', 'var(--group-ours)'];
    $poolLines = fn (string $figure) => array_map(fn (string $pool, int $index) => [
        'key' => ServerRange::poolKey($figure, $pool),
        'label' => $pool,
        'color' => $poolColors[$index % count($poolColors)],
        'dashed' => $index >= count($poolColors),
    ], $pools, array_keys($pools));
@endphp

{{-- PHP-FPM's pools and nginx over the range picked, from their status pages; how to read them while they are not. --}}
@if (! $readsPhp && ! $readsNginx)
    <x-card :title="__('PHP-FPM and nginx')" :description="__('Not read in this range. Given their local status pages, the shipper also sends how busy PHP-FPM\'s workers are, the requests waiting for one, the times pm.max_children was reached, and nginx\'s connections and requests a second.')">
        <div class="grid gap-3 text-[13px]">
            <p><span class="font-semibold">1.</span> {{ __('Open the status pages on a local address only: they tell anyone who reads them how busy the server is.') }}</p>
            <x-code :code="ShipperSetup::statusPagesConfig()" />
            <p><span class="font-semibold">2.</span> {{ __('Check they answer, then give them to the shipper (edgelog-ship 1.5.0 or newer) after --metrics, and restart it. Add one --fpm-status for each pool the sites use.') }}</p>
            <x-code :code="ShipperSetup::enableStatusCommands()" />
        </div>
    </x-card>
@else
    <h2 class="text-[15px] font-bold tracking-tight">{{ __('PHP-FPM and nginx') }}</h2>

    <x-stat-row :stats="array_values(array_filter([
        $readsPhp ? [__('PHP workers busy'), $latest->fpm_active === null ? '–' : $latest->fpm_active.' / '.$latest->fpm_total, __('Workers answering a request in the last sample, of those PHP-FPM had started, all pools summed up.')] : null,
        $readsPhp ? [__('Waiting for PHP'), $count($queuePeak), __('The most requests seen waiting for a free worker in a sample, over the range. Above 0, the pool was full.'), $queuePeak > 0 ? 'bad' : null] : null,
        $readsPhp ? [__('Max children reached'), $count($saturated), __('Times PHP-FPM wanted a worker more than pm.max_children allows, over the range: requests waited. Raise it if memory allows.'), $saturated > 0 ? 'bad' : null] : null,
        $readsPhp ? [__('Slow PHP requests'), $count($slow), __('Requests longer than request_slowlog_timeout, over the range; always 0 while it is not set.')] : null,
        $readsNginx ? [__('nginx connections'), $count($latest->nginx_connections), __('Connections open in the last sample, waiting ones (keep-alive) included.')] : null,
        $readsNginx ? [__('nginx requests'), $perSecond($range->average('nginx_requests')), __('Requests a second, on average over the range, of every site nginx serves.')] : null,
    ]))" />

    <div class="grid gap-4 lg:grid-cols-2">
        @if ($readsPhp)
            <x-charts.server-chart :title="__('PHP-FPM workers')" :points="$points" unit="count" :step="$range->step()" :timezone="$timezone" :lines="array_values(array_filter([
                ['key' => 'fpm_active', 'label' => $withPeaks ? __('Busy, average') : __('Busy'), 'color' => $first, 'area' => true],
                $withPeaks ? ['key' => 'fpm_active_peak', 'label' => __('Busy, peak'), 'color' => $first, 'dashed' => true] : null,
                ['key' => 'fpm_total', 'label' => __('Started'), 'color' => $second],
            ]))" />

            @if (count($pools) > 1)
                <x-charts.server-chart :title="$withPeaks ? __('PHP workers busy by pool, average') : __('PHP workers busy by pool')" :points="$points" unit="count" :step="$range->step()" :timezone="$timezone" :lines="$poolLines('active')" />

                <x-charts.server-chart :title="__('PHP requests a second by pool')" :points="$points" unit="per_second" :step="$range->step()" :timezone="$timezone" :lines="$poolLines('requests')" />
            @endif

            <x-charts.server-chart :title="__('Waiting for PHP')" :points="$points" unit="count" :step="$range->step()" :timezone="$timezone" :lines="[
                ['key' => 'fpm_queue', 'label' => __('Requests waiting, peak'), 'color' => $first, 'area' => true],
                ['key' => 'fpm_saturated', 'label' => __('Max children reached'), 'color' => $second],
            ]" />
        @endif

        <x-charts.server-chart :title="__('Requests a second')" :points="$points" unit="per_second" :step="$range->step()" :timezone="$timezone" :lines="array_values(array_filter([
            $readsNginx ? ['key' => 'nginx_requests', 'label' => __('nginx'), 'color' => $first, 'area' => true] : null,
            $readsPhp ? ['key' => 'fpm_requests', 'label' => __('PHP-FPM'), 'color' => $second] : null,
        ]))" />

        @if ($readsNginx)
            <x-charts.server-chart :title="__('nginx connections')" :points="$points" unit="count" :step="$range->step()" :timezone="$timezone" :lines="array_values(array_filter([
                ['key' => 'nginx_connections', 'label' => $withPeaks ? __('Open, average') : __('Open'), 'color' => $first, 'area' => true],
                $withPeaks ? ['key' => 'nginx_connections_peak', 'label' => __('Open, peak'), 'color' => $first, 'dashed' => true] : null,
            ]))" />
        @endif
    </div>
@endif
