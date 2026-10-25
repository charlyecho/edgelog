@php
    use App\Enums\CountKind;
    use App\Enums\DayStatus;
    use Illuminate\Support\Number;

    $stored = collect($period->days)->filter();
    $figure = fn (string $name) => (int) $stored->sum(fn ($day) => $day->quality[$name] ?? 0);
    $silences = $stored->flatMap(fn ($day) => array_map(fn (array $silence) => [...$silence, 'day' => $day->day->toDateString()], $day->quality['silences'] ?? []))
        ->sortByDesc('from')->values()->all();
    $lines = (int) $stored->sum('parsed');
    $unreadable = (int) $stored->sum('unparsed');
    $withheld = $period->withheld();
    $missing = $period->missing();
    $noStylesheets = $stored->filter(fn ($day) => $day->lacksStylesheets())->count();
    $good = $withheld === [] && $unreadable === 0 && $figure('duplicates') === 0 && $silences === [] && $noStylesheets === 0;
    $when = fn ($time) => $time === null ? __('never') : $time->setTimezone($site->timezone)->translatedFormat('j M H:i');
    $at = fn (int $time) => \Carbon\CarbonImmutable::createFromTimestamp($time, $site->timezone)->translatedFormat('j M H:i');

    $latestDay = $site->days()->latest('collected_at')->first();
    $latestAudit = $site->days()->whereNotNull('audited_at')->latest('audited_at')->first();
    $jobs = [
        [__('Log shipping (edgelog-ship)'), __('every few seconds'), $site->ingestStatus?->last_batch_at, 15],
        [__('Daily count of yesterday'), __('hourly, once a day is over'), $latestDay?->collected_at, 26 * 60],
        [__('Audit: the independent recount'), __('after each count'), $latestAudit?->audited_at, 26 * 60],
        [__('Stack check'), __('daily'), $site->stack?->checked_at, 26 * 60],
        [__('Sitemap read'), __('daily'), $site->sitemap?->fetched_at, 26 * 60],
        [__('robots.txt read'), __('daily'), $site->robots?->fetched_at, 26 * 60],
    ];
    $state = fn ($time, int $minutes) => match (true) {
        $time === null => ['never ran', 'muted'],
        $time->greaterThanOrEqualTo(now()->subMinutes($minutes)) => ['on time', 'good'],
        default => ['late', 'bad'],
    };
    $stateWords = ['never ran' => __('never ran'), 'on time' => __('on time'), 'late' => __('late')];
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="quality" :title="__('Data quality')" :period="$period" :choice="$choice" :without-figures="true"
    :lede="__('Can these figures be trusted? Each day is counted, checked to add up, then recounted from the raw log by a second road; a day the two disagree on is withheld. This also shows what can be seen of the log being whole.')">
    <x-notice :tone="$good ? 'good' : ($withheld !== [] ? 'bad' : 'warn')" :title="$good ? __('Good') : __('Worth a look')">
        @if ($good)
            {{ __('No problem found in this period\'s lines.') }}
        @else
            {{ collect([
                $withheld !== [] ? trans_choice(':count day withheld: its two counts disagree.|:count days withheld: their two counts disagree.', count($withheld)) : null,
                $unreadable > 0 ? trans_choice(':count line could not be read in the site\'s log format.|:count lines could not be read in the site\'s log format.', $unreadable, ['count' => number_format($unreadable)]) : null,
                $figure('duplicates') > 0 ? trans_choice(':count line repeats an earlier one exactly.|:count lines repeat an earlier one exactly.', $figure('duplicates'), ['count' => number_format($figure('duplicates'))]) : null,
                $silences !== [] ? trans_choice(':count stretch of more than 10 minutes without a line.|:count stretches of more than 10 minutes without a line.', count($silences)) : null,
                $noStylesheets > 0 ? trans_choice('On :count busy day the log has no stylesheet: the web server may leave them out of its log.|On :count busy days the log has no stylesheet: the web server may leave them out of its log.', $noStylesheets) : null,
            ])->filter()->implode(' ') }}
        @endif
    </x-notice>

    <x-stat-row :stats="[
        [__('Lines read'), $lines, __('Every line of the period\'s days, every host.')],
        [__('Unreadable lines'), $unreadable, __('Lines the site\'s log format could not read. Over 1% of a day withholds it.'), $unreadable > 0 ? 'bad' : null],
        [__('Duplicate lines'), $figure('duplicates'), __('Lines identical, field for field, to an earlier one in the same second: counted, never dropped.')],
        [__('Lines without host or time'), $figure('short'), __('Lines in a combined format: no host and no answer time. The extended nginx format has both.')],
        [__('Silent stretches'), count($silences), __('More than 10 minutes without a single line: a quiet night, or lines that never reached Edgelog.')],
        [__('Browser-like requests moved to bots'), array_sum($period->counts(CountKind::SecurityFake)), __('Requests that looked like a browser but never drew a page, read too fast, or crawled robots.txt.')],
    ]" />

    <x-rows-table :title="__('Each day')" :unit="__('How each day of the period was counted and checked.')"
        :columns="[[__('Day'), 'left'], [__('State'), 'left'], [__('Lines'), 'right'], [__('Unreadable'), 'right'], [__('Duplicates'), 'right'], [__('Counted'), 'right'], [__('Audited'), 'right'], [__('Note'), 'left']]"
        :rows="collect(array_reverse($period->days, true))->map(fn ($day, $date) => $day === null ? [
            \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M'),
            ['label' => __('Not recorded'), 'tone' => 'muted'], '–', '–', '–', '–', '–',
            ['label' => __('The logs hold nothing for this day.'), 'tone' => 'muted'],
        ] : [
            \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M'),
            ['label' => $day->status->label(), 'tone' => match ($day->status) { DayStatus::Verified => 'good', DayStatus::Withheld => 'bad', default => 'warn' }],
            number_format($day->parsed),
            number_format($day->unparsed),
            isset($day->quality) ? number_format($day->quality['duplicates']) : '–',
            $when($day->collected_at),
            $when($day->audited_at),
            ['label' => collect([
                $day->isPartial() ? __('From :time only: the logs start then.', ['time' => $day->partial_from->setTimezone($site->timezone)->format('H:i')]) : null,
                $day->lacksStylesheets() ? __('No stylesheet in the log.') : null,
                ...($day->problems ?? []),
            ])->filter()->implode(' ') ?: '–', 'tone' => $day->problems ? 'bad' : 'muted'],
        ])->values()->all()" :shown="31" />

    <x-rows-table :title="__('Silent stretches')" :unit="__('More than 10 minutes without a single line, newest first.')"
        :columns="[[__('From'), 'left'], [__('To'), 'left'], [__('Minutes'), 'right']]"
        :rows="array_map(fn (array $silence) => [$at($silence['from']), $at($silence['to']), number_format($silence['minutes'])], $silences)"
        :empty="__('No stretch of more than 10 minutes without a line.')" />

    <x-rows-table :title="__('What runs, now')" :unit="__('When each part last ran; the period does not apply. A late part is why figures stop moving.')"
        :columns="[[__('State'), 'left'], [__('Part'), 'left'], [__('Runs'), 'left'], [__('Last ran'), 'right']]"
        :rows="array_map(function (array $job) use ($state, $stateWords, $when) {
            [$label, $tone] = $state($job[2], $job[3]);

            return [['label' => $stateWords[$label], 'tone' => $tone], $job[0], $job[1], $when($job[2])];
        }, $jobs)" />

    <p class="text-[13px] text-ink-2">
        {{ __('Log files kept on this server:') }}
        {{ collect($files)->map(fn (array $file) => $file['name'].' ('.Number::fileSize($file['bytes'], 1).')')->implode(', ') ?: __('none yet') }}.
    </p>
</x-section-frame>
