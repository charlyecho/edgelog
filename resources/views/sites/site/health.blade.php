@php
    use App\Analytics\Health\HealthCollector;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;

    $statuses = $health->statuses();
    $requests = $period->requests();
    $people = $period->group(TrafficGroup::People);
    $filesPerPage = $people->views > 0 ? (int) round($people->requests / $people->views) : null;
    $groupsSum = array_sum(array_map(fn (TrafficGroup $group) => $period->group($group)->requests, TrafficGroup::cases()));
    $loadNames = [
        TrafficGroup::People->value => __('Files loaded for people\'s visits'),
        TrafficGroup::AnswerEngines->value => __('Fetched by AI assistants'),
        TrafficGroup::SearchEngines->value => __('Fetched by search engines'),
        TrafficGroup::Bots->value => __('Bots and scanners'),
        TrafficGroup::Ours->value => __('Your own tools'),
    ];
    $speedLabels = [
        'under 0.2 s' => __('under 0.2 s'),
        '0.2 to 0.5 s' => __('0.2 to 0.5 s'),
        '0.5 to 1 s' => __('0.5 to 1 s'),
        '1 to 3 s' => __('1 to 3 s'),
        '3 s or more' => __('3 s or more'),
    ];
    $statusWords = [
        '2xx' => __('2xx answered'),
        '3xx' => __('3xx redirected'),
        '404' => __('404 not found'),
        '4xx' => __('4xx other client errors'),
        '5xx' => __('5xx server errors'),
        'other' => __('Any other status'),
    ];
    $statusTones = [
        '2xx' => 'var(--good)',
        '3xx' => 'var(--group-people)',
        '404' => 'color-mix(in srgb, var(--warn) 75%, var(--bad))',
        '4xx' => 'var(--group-bots)',
        '5xx' => 'var(--bad)',
        'other' => 'var(--ink-3)',
    ];

    $serverErrors = $health->status('5xx');
    $notFound = $statuses[TrafficGroup::People->value]['404'];
    $speeds = $health->speeds();
    $timed = array_sum($speeds);
    $slow = $health->slowViews();
    $tallest = max([1, ...$speeds]);
    $typical = $health->speedAt(0.5);
    $slowest = $health->speedAt(0.95);
    $heartbeat = $health->heartbeat();

    $byStatus = collect($statuses)->sortByDesc('all');
    $when = fn ($time) => $time->setTimezone($site->timezone)->translatedFormat('j M H:i');
    $label = fn (string $name) => $name === CountKind::OTHERS ? __('(all others)') : $name;
    $pathRows = function (CountKind $kind) use ($period, $label) {
        $counts = $period->counts($kind);
        $total = array_sum($counts);
        arsort($counts);

        return collect($counts)->map(fn (int $count, $path) => [
            ['label' => $label((string) $path), 'mono' => true],
            number_format($count),
            $total > 0 ? round($count / $total * 100).'%' : '',
        ])->values()->all();
    };
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="health" :title="__('Site health')" :period="$period" :choice="$choice"
    :lede="__('Every response, by status and by who asked; server errors; how fast the server answered people\'s page views; and whether the site was up.')">
    @unless ($health->isWhole())
        <x-notice tone="warn">{{ __('Some days of this period were counted before health was kept: count them again to see them here. The figures below leave them out.') }}</x-notice>
    @endunless

    <section class="grid gap-2">
        <header>
            <h3 class="text-[15px] font-bold">{{ __('Server requests (every file: pages, images, scripts)') }}</h3>
            <p class="text-[13px] text-ink-2">{{ __('Every request the server answered in this period, by who asked. This is server load, not people.') }}</p>
        </header>
        <div class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <p class="flex flex-wrap items-baseline gap-2">
                <span class="text-[26px] font-bold tabular-nums">{{ number_format($requests) }}</span>
                <span class="text-[13px] text-ink-2">{{ trans_choice('requests over the :count whole day: every file the server sent, pages, images, scripts and data.|requests over the :count whole days: every file the server sent, pages, images, scripts and data.', $period->verified()->count()) }}</span>
            </p>
            <div class="mt-3 flex h-3 gap-0.5 overflow-hidden rounded-full bg-sunk" role="img" aria-label="{{ __('Requests by group') }}">
                @foreach (TrafficGroup::cases() as $group)
                    @if ($period->group($group)->requests > 0)
                        <span style="flex-grow: {{ $period->group($group)->requests }}; background: var(--group-{{ $group->value }})" title="{{ $loadNames[$group->value] }}: {{ number_format($period->group($group)->requests) }}"></span>
                    @endif
                @endforeach
            </div>
            <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                @foreach (TrafficGroup::cases() as $group)
                    @php($count = $period->group($group)->requests)
                    <div>
                        <p class="flex items-center gap-1.5 text-xs text-ink-2"><span class="size-2 flex-none rounded-[2px]" style="background: var(--group-{{ $group->value }})"></span>{{ $loadNames[$group->value] }}</p>
                        <p class="text-lg font-bold tabular-nums">{{ number_format($count) }}</p>
                        <p class="text-xs text-ink-3">{{ __(':share of requests', ['share' => $requests > 0 ? round($count / $requests * 100).'%' : '0%']) }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-ink-3">
                {{ $filesPerPage !== null
                    ? __('One page view loads about :count files here, so requests are much larger than pages read.', ['count' => $filesPerPage])
                    : __('One page view loads many files, so requests are much larger than pages read.') }}
            </p>
            <p @class(['mt-3 flex items-center gap-2 border-t border-grid pt-3 text-[13px]', 'text-ink-2' => $groupsSum === $requests, 'text-bad' => $groupsSum !== $requests])>
                <x-icon :name="$groupsSum === $requests ? 'check' : 'info'" class="size-4" />
                {{ $groupsSum === $requests
                    ? __('The five groups add up to :count exactly.', ['count' => number_format($requests)])
                    : __('The groups do not add up to the requests (:sum against :requests). These figures should not be trusted.', ['sum' => number_format($groupsSum), 'requests' => number_format($requests)]) }}
            </p>
        </div>
    </section>

    <x-stat-row :stats="[
        [__('Server errors (5xx)'), $serverErrors, $serverErrors > 0 ? __('Responses to anyone; should be zero.') : __('None: as it should be.'), $serverErrors > 0 ? 'bad' : null],
        [__('Page not found, seen by people'), $notFound, __('404s people met, not scanners.')],
        [__('Typical answer time'), $typical === null ? '–' : $speedLabels[$typical], __('The median page view for people; only buckets are kept.')],
        [__('Slowest 5%'), $slowest === null ? '–' : $speedLabels[$slowest], __('The 95th percentile falls in this bucket.')],
        [__('Slow page views'), $timed > 0 ? round($slow / $timed * 100).'%' : '–', trans_choice(':count took a second or more.|:count took a second or more.', $slow)],
    ]" />

    <section class="grid gap-2">
        <h3 class="text-[15px] font-bold">{{ __('Is the site up?') }}</h3>
        <div class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <p class="font-bold">{{ __('Our heartbeat') }}</p>
            <p class="text-[13px] text-ink-2">{{ __('The server asks for its own page every 5 minutes') }}</p>
            @if ($heartbeat === null)
                <p class="mt-2 text-[13px] text-ink-2">{{ __('Not set up: no heartbeat reached the log in this period. Add this line to a crontab on the site\'s server (crontab -e); its requests count as your own traffic:') }}</p>
                <x-code class="mt-2" :code="'*/5 * * * * curl -s -o /dev/null -A \'edgelog-heartbeat\' '.rtrim($site->url, '/').'/robots.txt'" />
            @else
                <p class="mt-2 text-[26px] font-bold tabular-nums">{{ number_format($heartbeat['up'] / $heartbeat['expected'] * 100, 2) }}%</p>
                <p class="text-[13px] text-ink-2">
                    @if ($heartbeat['up'] === $heartbeat['expected'])
                        {{ trans_choice('All :count expected beat answered.|All :count expected beats answered.', $heartbeat['expected']) }}
                    @else
                        {{ __(':up of :expected expected beats answered.', ['up' => number_format($heartbeat['up']), 'expected' => number_format($heartbeat['expected'])]) }}
                    @endif
                    @if ($heartbeat['failed'] > 0)
                        <span class="text-bad">{{ trans_choice(':count beat was answered with an error.|:count beats were answered with an error.', $heartbeat['failed']) }}</span>
                    @endif
                    @if ($heartbeat['days'] < $period->verified()->count())
                        {{ trans_choice('Over the :count day it beat on.|Over the :count days it beat on.', $heartbeat['days']) }}
                    @endif
                </p>
            @endif
        </div>

        @if ($heartbeat !== null)
            <x-rows-table :title="__('Outages and heartbeat gaps')"
                :unit="__('Each stretch of more than 10 minutes without an answered heartbeat: the site was down, or the heartbeat itself stopped.')"
                :columns="[[__('From'), 'left'], [__('To'), 'left'], [__('Minutes'), 'right']]"
                :rows="array_map(fn (array $gap) => [$when($gap['from']), $when($gap['to']), number_format($gap['minutes'])], $heartbeat['gaps'])"
                :empty="__('No gap longer than 10 minutes in this period.')" />
        @endif
    </section>

    <section class="grid gap-2">
        <h3 class="text-[15px] font-bold">{{ __('Responses by status, for each group') }}</h3>
        <div class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
                @foreach ($statusWords as $status => $word)
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-[2px]" style="background: {{ $statusTones[$status] }}"></span>{{ $word }}</li>
                @endforeach
            </ul>
            <div class="grid gap-3">
                @foreach (TrafficGroup::cases() as $group)
                    @php($row = $statuses[$group->value])
                    <div>
                        <p class="flex items-center justify-between gap-3 text-[13px]">
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-[2px]" style="background: var(--group-{{ $group->value }})"></span>{{ $group->label() }}</span>
                            <span class="text-xs text-ink-3 tabular-nums">{{ trans_choice(':count request|:count requests', $row['all'], ['count' => number_format($row['all'])]) }}</span>
                        </p>
                        <div class="mt-1 flex h-2 gap-px overflow-hidden rounded-full bg-sunk" role="img"
                            aria-label="{{ $group->label() }}: {{ collect(HealthCollector::STATUSES)->filter(fn ($status) => $row[$status] > 0)->map(fn ($status) => $status.' '.number_format($row[$status]))->implode(', ') }}">
                            @foreach (HealthCollector::STATUSES as $status)
                                @if ($row[$status] > 0)
                                    <span style="flex-grow: {{ $row[$status] }}; background: {{ $statusTones[$status] }}" title="{{ $status }}: {{ number_format($row[$status]) }}"></span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <x-rows-table :columns="[[__('Group'), 'left'], ...array_map(fn ($status) => [$status === 'other' ? __('other') : $status, 'right'], HealthCollector::STATUSES), [__('All'), 'right']]"
            :rows="$byStatus->map(fn (array $row, string $group) => [TrafficGroup::from($group)->label(), ...array_map(fn ($status) => number_format($row[$status]), HealthCollector::STATUSES), number_format($row['all'])])->values()->all()" />
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <h3 class="text-[14px] font-bold">{{ __('How fast people\'s pages were answered') }}</h3>
            <p class="mb-3 text-xs text-ink-3">{{ __('Time the server took to answer each page view') }}</p>
            @if ($timed === 0)
                <p class="py-3 text-ink-3">{{ __('No page view had its answer time in the log. Add $request_time to the log format to see it.') }}</p>
            @else
                <div class="flex h-40 items-end gap-3">
                    @foreach ($speeds as $speed => $views)
                        <div class="flex h-full flex-1 flex-col justify-end" title="{{ $speedLabels[$speed] }}: {{ trans_choice(':count page view|:count page views', $views, ['count' => number_format($views)]) }}">
                            <span class="mb-0.5 text-center text-[11px] text-ink-3 tabular-nums">{{ $views > 0 ? number_format($views) : '' }}</span>
                            <span class="block rounded-t-[3px]" style="height: {{ $views > 0 ? max(2, $views / $tallest * 100) : 0 }}%; background: {{ in_array($speed, HealthCollector::SLOW, true) ? 'var(--warn)' : 'var(--good)' }}"></span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-3 border-t border-rule pt-1 text-[11px] text-ink-3">
                    @foreach ($speeds as $speed => $views)
                        <span class="flex-1 text-center">{{ $speedLabels[$speed] }}</span>
                    @endforeach
                </div>
            @endif
            @if ($health->notLogged() > 0)
                <p class="mt-2 text-xs text-ink-3">{{ trans_choice(':count page view had no time in the log line.|:count page views had no time in the log line.', $health->notLogged(), ['count' => number_format($health->notLogged())]) }}</p>
            @endif
        </section>

        <x-rows-table :title="__('Slow pages')" :unit="__('People\'s page views answered in a second or more')"
            :columns="[[__('Page'), 'left'], [__('Slow views'), 'right'], [__('Share'), 'right']]"
            :rows="$pathRows(CountKind::HealthSlowPage)" :empty="__('No page took a second or more.')" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-rows-table :title="__('Server errors by page')"
            :columns="[[__('Page'), 'left'], [__('5xx'), 'right'], [__('Share'), 'right']]"
            :rows="$pathRows(CountKind::HealthServerError)" :empty="__('No server errors. Good.')" />

        <x-rows-table :title="__('Form posts')" :unit="__('Counted, never read: what was sent is not in the log.')"
            :columns="[[__('From'), 'left'], [__('Page'), 'left'], [__('Posts'), 'right']]"
            :rows="array_map(fn (array $post) => [$post['group']?->label() ?? __('(all others)'), ['label' => $label($post['path']), 'mono' => true], number_format($post['posts'])], $health->posts())"
            :empty="__('No form posts in this period.')" />
    </div>
</x-section-frame>
