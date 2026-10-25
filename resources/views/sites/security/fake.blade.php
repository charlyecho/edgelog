@php
    use App\Analytics\Classification\VisitEvidence;
    use App\Analytics\Labels;
    use App\Analytics\Security\SecurityNames;
    use App\Enums\CountKind;

    $reasons = $period->counts(CountKind::SecurityFake);
    arsort($reasons);
    $reasonWords = [
        VisitEvidence::NOT_RENDERED => __('Never rendered the page'),
        VisitEvidence::TOO_FAST => __('Too fast for a person'),
        VisitEvidence::CRAWLER => __('Browser-like crawler (robots.txt or sitemap)'),
    ];
    $requests = $security->figure('fake_requests');
    $pages = collect($period->top(CountKind::SecurityFakePage, 20))->map(fn ($count, $path) => [
        'label' => $path === CountKind::OTHERS ? __('(all others)') : $path,
        'count' => $count,
    ])->values()->all();
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="fake" :title="__('Fake browsers and scrapers')" :period="$period" :choice="$choice"
    :lede="__('Which “browsers” were not people, and what did they take?')">
    <x-security-partial :security="$security" />

    <x-stat-row :stats="[
        [__('Fake-browser requests'), $requests, __('Requests from a browser\'s user agent the count filed as a bot.')],
        [__('Never rendered'), $reasons[VisitEvidence::NOT_RENDERED] ?? 0, __('Asked for pages but never for the scripts or styles that draw them.')],
        [__('Too fast'), $reasons[VisitEvidence::TOO_FAST] ?? 0, __(':pages pages or more within :seconds seconds of the first.', ['pages' => config('analytics.too_fast_pages'), 'seconds' => config('analytics.too_fast_seconds')])],
        [__('Browser-like crawlers'), $reasons[VisitEvidence::CRAWLER] ?? 0, __('Fetched robots.txt or a sitemap, which a person never does.')],
    ]" />

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <x-top-list :title="__('What gave them away')" :unit="__('requests')" :rows="collect($reasons)->map(fn ($count, $reason) => ['label' => $reasonWords[$reason] ?? $reason, 'count' => $count])->values()->all()"
            :total="$requests" tint="var(--group-bots)" />
        <x-top-list :title="__('Pages they took')" :unit="__('requests, each day\'s most taken pages summed')" :rows="$pages" :total="$requests" tint="var(--group-bots)" :shown="10" />
    </div>

    <x-rows-table :title="__('Fake browsers by address')" :unit="__('Addresses are kept :days days.', ['days' => config('analytics.security.kept_days')])"
        :columns="[[__('Address'), 'left'], [__('Network'), 'left'], [__('Country'), 'left'], [__('What gave it away'), 'left'], [__('Requests'), 'right'], [__('Pages taken'), 'left']]"
        :rows="collect($rows)->sortByDesc('fake_requests')->map(fn ($row) => [
            ['label' => $row['address'], 'mono' => true, 'href' => route('teams.sites.security.ip', [$team, $site, 'ip' => $row['address'], 'days' => $choice]), 'drawer' => true],
            ['label' => SecurityNames::network($row['address']), 'mono' => true],
            $row['country'] === '' ? ['label' => __('Not recorded')] : ['label' => $row['country'], 'flag' => Labels::flag($row['country'])],
            implode(', ', array_map(fn ($sign) => $reasonWords[$sign] ?? null, array_intersect($row['signs'], array_keys($reasonWords)))),
            number_format($row['fake_requests']),
            ['label' => implode(', ', array_slice(array_keys($row['paths']), 0, 3)), 'mono' => true],
        ])->values()->all()" :shown="25" :empty="__('No fake browser in this period.')" />
</x-section-frame>
