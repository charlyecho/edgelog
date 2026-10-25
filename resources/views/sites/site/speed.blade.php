@php
    use App\Analytics\Health\HealthCollector;
    use App\Analytics\Labels;

    $speedLabels = [
        'under 0.2 s' => __('under 0.2 s'),
        '0.2 to 0.5 s' => __('0.2 to 0.5 s'),
        '0.5 to 1 s' => __('0.5 to 1 s'),
        '1 to 3 s' => __('1 to 3 s'),
        '3 s or more' => __('3 s or more'),
    ];
    $speedTones = ['under 0.2 s' => 'var(--good)', '0.2 to 0.5 s' => 'color-mix(in srgb, var(--good) 60%, var(--surface))', '0.5 to 1 s' => 'color-mix(in srgb, var(--warn) 55%, var(--surface))', '1 to 3 s' => 'var(--warn)', '3 s or more' => 'var(--bad)'];
    $speeds = $health->speeds();
    $timed = array_sum($speeds);
    $slow = $health->slowViews();
    $typical = $health->speedAt(0.5);
    $slowest = $health->speedAt(0.95);
    $pages = $health->pageTimes();
    $ms = fn (int $milliseconds) => __(':ms ms', ['ms' => number_format($milliseconds)]);
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="speed" :title="__('Speed')" :period="$period" :choice="$choice"
    :lede="__('How fast the server answered people\'s page views ($request_time): the whole period, each day and each page. The time the browser then takes to draw the page is not in the log.')">
    @unless ($health->isWhole())
        <x-notice tone="warn">{{ __('Some days of this period were counted before health was kept: count them again to see them here. The figures below leave them out.') }}</x-notice>
    @endunless

    @if ($timed === 0)
        <x-notice tone="warn" :title="__('No answer time in the log')">
            {{ __('None of people\'s page views had the server\'s answer time in its line. Ship the log in nginx\'s extended format (Site settings): it writes $request_time.') }}
        </x-notice>
    @else
        <x-stat-row :stats="[
            [__('Typical answer time'), $speedLabels[$typical], __('The median page view for people; only buckets are kept.')],
            [__('Slowest 5%'), $speedLabels[$slowest], __('The 95th percentile falls in this bucket.')],
            [__('Slow page views'), round($slow / $timed * 100).'%', trans_choice(':count took a second or more.|:count took a second or more.', $slow)],
            [__('Timed page views'), $timed, $health->notLogged() > 0 ? trans_choice(':count page view had no time in the log line.|:count page views had no time in the log line.', $health->notLogged(), ['count' => number_format($health->notLogged())]) : __('Every page view had its time in the log line.')],
        ]" />

        <section class="grid gap-2">
            <header>
                <h3 class="text-[15px] font-bold">{{ __('Answer times, by day') }}</h3>
                <p class="text-[13px] text-ink-2">{{ __('People\'s page views of each day by the time the server took. A day without figures is left blank, never drawn as fast.') }}</p>
            </header>
            <div class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
                    @foreach ($speedLabels as $speed => $label)
                        <li class="flex items-center gap-1.5"><span class="size-2 rounded-[2px]" style="background: {{ $speedTones[$speed] }}"></span>{{ $label }}</li>
                    @endforeach
                </ul>
                <div class="grid gap-1.5">
                    @foreach (array_reverse($health->dailySpeeds(), true) as $date => $day)
                        <div class="grid grid-cols-[5.5rem_minmax(0,1fr)_3.5rem] items-center gap-3 text-xs">
                            <span class="whitespace-nowrap text-ink-2">{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M') }}</span>
                            @if ($day === null || array_sum($day) === 0)
                                <span class="h-2.5 rounded-full border border-dashed border-rule"></span>
                                <span class="text-right text-ink-3">–</span>
                            @else
                                <span class="flex h-2.5 gap-px overflow-hidden rounded-full bg-sunk"
                                    title="{{ collect($day)->filter()->map(fn ($views, $speed) => $speedLabels[$speed].': '.number_format($views))->implode(', ') }}">
                                    @foreach ($day as $speed => $views)
                                        @if ($views > 0)
                                            <span style="flex-grow: {{ $views }}; background: {{ $speedTones[$speed] }}"></span>
                                        @endif
                                    @endforeach
                                </span>
                                <span class="text-right text-ink-2 tabular-nums" title="{{ __('Slow page views') }}">{{ round(array_sum(array_intersect_key($day, array_flip(HealthCollector::SLOW))) / array_sum($day) * 100) }}%</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-ink-3">{{ __('The share at the end of each line is the day\'s slow page views (a second or more).') }}</p>
            </div>
        </section>

        <x-rows-table :title="__('Answer time by page')" :unit="__('People\'s timed page views, slowest average first.')"
            :columns="[[__('Page'), 'left'], [__('Average'), 'right'], [__('Timed views'), 'right'], [__('Slow views'), 'right']]"
            :rows="array_map(fn (array $page) => [
                ['label' => $page['path'], 'icon' => Labels::pageIcon($page['path']), 'mono' => true],
                ['label' => $ms($page['average']), 'tone' => $page['average'] >= 1000 ? 'bad' : ($page['average'] >= 500 ? 'warn' : null)],
                number_format($page['views']),
                number_format($page['slow']),
            ], $pages)" :shown="20" />
    @endif
</x-section-frame>
