@php
    use App\Analytics\Labels;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;

    $statuses = $health->statuses();
    $notFound = $period->counts(CountKind::HealthNotFound);
    unset($notFound[CountKind::OTHERS]);
    $met = $statuses[TrafficGroup::People->value]['404'];
    $broken = $health->brokenPaths();
    $open = array_values(array_filter($broken, fn (array $row) => ! $row['fixed']));
    $fixed = array_values(array_filter($broken, fn (array $row) => $row['fixed']));
    $day = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->translatedFormat('j M');
    $from = fn (array $sources) => collect($sources)->take(3)->map(fn ($count, $source) => "{$source} {$count}")->implode(', ');

    $who = collect(TrafficGroup::cases())
        ->map(fn (TrafficGroup $group) => ['label' => $group->label(), 'count' => $statuses[$group->value]['404'], 'tint' => "var(--group-{$group->value})"])
        ->filter(fn (array $row) => $row['count'] > 0)->sortByDesc('count')->values()->all();
    $engines = $period->top(CountKind::HealthEngineNotFound, 50);
    $probes = $period->top(CountKind::SecurityPath, 50);
    $brokenRows = fn (array $rows) => array_map(fn (array $row) => [
        ['label' => $row['path'], 'icon' => Labels::pageIcon($row['path']), 'mono' => true],
        number_format($row['people']),
        ['label' => $from($row['from']) ?: '–', 'tone' => 'muted'],
        $day($row['first']),
        $day($row['last']),
        ['label' => $row['fixed'] ? __('fixed') : __('open'), 'tone' => $row['fixed'] ? 'good' : 'bad'],
    ], $rows);
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="not-found" :title="__('Not found')" :period="$period" :choice="$choice"
    :lede="__('The pages people could not find, where they came from and whether each is fixed; and the missing pages crawlers, assistants and scanners asked for.')">
    @unless ($health->isWhole())
        <x-notice tone="warn">{{ __('Some days of this period were counted before health was kept: count them again to see them here. The figures below leave them out.') }}</x-notice>
    @endunless

    <x-stat-row :stats="[
        [__('Missing pages people met'), $met, __('Every 404 a person was answered: pages, files and calls alike.')],
        [__('Pages people could not find'), count($notFound), __('Distinct paths people met a 404 on.')],
        [__('Scanner probes'), $period->group(TrafficGroup::Bots)->probes, __('Requests for files and panels the site does not have, by scanners: on Security.')],
    ]" />

    <x-rows-table :title="__('Pages people could not find')" :unit="trans_choice(':count still broken: people hit it and it is not read as a page since. Fix the open ones first: redirect them to the page people meant.|:count still broken: people hit these and they are not read as pages since. Fix the open ones first: redirect them to the page people meant.', count($open))"
        :columns="[[__('Page'), 'left'], [__('Times hit'), 'right'], [__('Came from'), 'left'], [__('First seen'), 'right'], [__('Last seen'), 'right'], [__('Status'), 'left']]"
        :rows="$brokenRows([...$open, ...$fixed])" :shown="20" :empty="__('People met no missing page in this period.')" />

    <div class="grid gap-4 lg:grid-cols-2">
        <x-top-list :title="__('Who hit missing pages')" :unit="__('Every 404 by group, probes included')" :rows="$who" :total="array_sum(array_column($who, 'count'))" tint="var(--warn)" />

        <x-top-list :title="__('Other missing pages')" :unit="__('404s and 410s answered to search crawlers and AI assistants')"
            :rows="collect($engines)->map(fn ($count, $path) => ['label' => $path === CountKind::OTHERS ? __('(all others)') : $path, 'icon' => Labels::pageIcon((string) $path), 'count' => $count])->values()->all()"
            :total="array_sum($engines)" tint="var(--group-answer_engines)" :shown="8" />
    </div>

    <details class="rounded-[10px] border border-rule bg-surface shadow-card">
        <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
            <span class="font-bold">{{ trans_choice('Probes and scanner noise (:count path)|Probes and scanner noise (:count paths)', count($probes)) }}</span>
            <a href="{{ route('teams.sites.security.scanners', [$team, $site, 'days' => $choice]) }}" class="text-[13px] text-ink-2">{{ __('Who is scanning, on Security') }}</a>
        </summary>
        <div class="border-t border-rule">
            <x-top-list title="" unit="" :rows="collect($probes)->map(fn ($count, $path) => ['label' => $path === CountKind::OTHERS ? __('(all others)') : $path, 'count' => $count])->values()->all()"
                :total="array_sum($probes)" tint="var(--group-bots)" :shown="15" class="rounded-none border-0 shadow-none" />
        </div>
    </details>
</x-section-frame>
