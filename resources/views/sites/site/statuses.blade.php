@php
    use App\Analytics\Health\HealthCollector;
    use App\Analytics\Labels;
    use App\Enums\TrafficGroup;

    $requests = $period->requests();
    $notFound = $health->status('404');
    $clientErrors = $health->status('4xx') + $notFound;
    $serverErrors = $health->status('5xx');
    $errors = $clientErrors + $serverErrors;
    $codes = $health->codes();
    $days = $health->dailyErrors();
    $mostErrors = max([1, ...array_map(fn (?array $day) => $day === null ? 0 : $day['404'] + $day['4xx'] + $day['5xx'], $days)]);
    $share = fn (int $count, int $of) => $of > 0 ? number_format($count / $of * 100, $count / $of < 0.1 ? 1 : 0).'%' : '–';
    $errorWords = [
        '404' => __('404 not found'),
        '4xx' => __('4xx other client errors'),
        '5xx' => __('5xx server errors'),
    ];
    $errorTones = [
        '404' => 'color-mix(in srgb, var(--warn) 75%, var(--bad))',
        '4xx' => 'var(--group-bots)',
        '5xx' => 'var(--bad)',
    ];
    $codeTone = fn (?int $code) => match (true) {
        $code === null => null,
        $code >= 500 => 'bad',
        $code >= 400 => 'warn',
        $code >= 200 && $code < 300 => 'good',
        default => null,
    };
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="statuses" :title="__('Status codes')" :period="$period" :choice="$choice"
    :lede="__('Every status code the server answered, for each group; the errors of each day; and the pages answered with an error.')">
    @unless ($health->isWhole())
        <x-notice tone="warn">{{ __('Some days of this period were counted before health was kept: count them again to see them here. The figures below leave them out.') }}</x-notice>
    @endunless

    <x-stat-row :stats="[
        [__('Error rate'), $share($errors, $requests), __('Requests answered with a 4xx or a 5xx, out of every request.'), $requests > 0 && $serverErrors / $requests >= 0.01 ? 'bad' : null],
        [__('Client errors (4xx)'), $clientErrors, trans_choice(':count of them is a 404 not found.|:count of them are 404 not found.', $notFound, ['count' => number_format($notFound)])],
        [__('Server errors (5xx)'), $serverErrors, $serverErrors > 0 ? __('Responses to anyone; should be zero.') : __('None: as it should be.'), $serverErrors > 0 ? 'bad' : null],
        [__('Status codes seen'), count($codes), __('Distinct status codes the server answered in this period.')],
    ]" />

    <section class="grid gap-2">
        <header>
            <h3 class="text-[15px] font-bold">{{ __('Errors, by day') }}</h3>
            <p class="text-[13px] text-ink-2">{{ __('Each day\'s 4xx and 5xx answers, against the day with the most. The share at the end of each line is the day\'s error rate.') }}</p>
        </header>
        <div class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
                @foreach ($errorWords as $status => $word)
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-[2px]" style="background: {{ $errorTones[$status] }}"></span>{{ $word }}</li>
                @endforeach
            </ul>
            <div class="grid gap-1.5">
                @foreach (array_reverse($days, true) as $date => $day)
                    @php($dayErrors = $day === null ? 0 : $day['404'] + $day['4xx'] + $day['5xx'])
                    <div class="grid grid-cols-[5.5rem_minmax(0,1fr)_3.5rem] items-center gap-3 text-xs">
                        <span class="whitespace-nowrap text-ink-2">{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M') }}</span>
                        @if ($day === null || $day['requests'] === 0)
                            <span class="h-2.5 rounded-full border border-dashed border-rule"></span>
                            <span class="text-right text-ink-3">–</span>
                        @else
                            <span class="h-2.5 overflow-hidden rounded-full bg-sunk"
                                title="{{ collect($errorWords)->map(fn ($word, $status) => $word.': '.number_format($day[$status]))->implode(', ') }}">
                                <span class="flex h-full gap-px" style="width: {{ $dayErrors / $mostErrors * 100 }}%">
                                    @foreach ($errorWords as $status => $word)
                                        @if ($day[$status] > 0)
                                            <span style="flex-grow: {{ $day[$status] }}; background: {{ $errorTones[$status] }}"></span>
                                        @endif
                                    @endforeach
                                </span>
                            </span>
                            <span @class(['text-right tabular-nums', 'text-bad' => $day['5xx'] > 0, 'text-ink-2' => $day['5xx'] === 0]) title="{{ trans_choice(':count error|:count errors', $dayErrors, ['count' => number_format($dayErrors)]) }}">{{ $share($dayErrors, $day['requests']) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="grid gap-2">
        @unless ($health->hasCodes())
            <x-notice tone="warn">{{ __('Some days of this period were counted before exact status codes were kept: count them again to see every code. The table below leaves them out.') }}</x-notice>
        @endunless

        <x-rows-table :title="__('Responses by status code')" :unit="__('Every request of the period, by the exact code the server answered and by who asked.')"
            :columns="[[__('Code'), 'left'], [__('Meaning'), 'left'], ...array_map(fn (TrafficGroup $group) => [$group->label(), 'right'], TrafficGroup::cases()), [__('All'), 'right'], [__('Share'), 'right']]"
            :rows="array_map(fn (array $row) => [
                ['label' => (string) $row['code'], 'mono' => true, 'tone' => $codeTone($row['code'])],
                HealthCollector::codeMeaning($row['code']),
                ...array_map(fn (TrafficGroup $group) => $row['groups'][$group->value] > 0 ? number_format($row['groups'][$group->value]) : '–', TrafficGroup::cases()),
                number_format($row['all']),
                $share($row['all'], $requests),
            ], $codes)" :shown="20" />
    </section>

    <x-rows-table :title="__('Errors by page')" :unit="__('Pages answered with a 4xx or a 5xx, every group together. The 404s are in the Not found tab.')"
        :columns="[[__('Code'), 'left'], [__('Page'), 'left'], [__('Requests'), 'right']]"
        :rows="array_map(fn (array $row) => [
            $row['code'] === null ? '' : ['label' => $row['code'].' '.HealthCollector::codeMeaning($row['code']), 'tone' => $codeTone($row['code'])],
            ['label' => $row['code'] === null ? __('(all others)') : $row['path'], 'icon' => Labels::pageIcon($row['path']), 'mono' => true],
            number_format($row['requests']),
        ], $health->errorPages())" :shown="20" :empty="__('No page was answered with an error other than a 404.')" />
</x-section-frame>
