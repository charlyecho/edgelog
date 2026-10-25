@php
    use App\Analytics\Labels;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $others = CountKind::OTHERS;
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';
    $mostFirst = function (array $counts) use ($others) {
        $tail = $counts[$others] ?? null;
        $unknown = $counts['Unknown'] ?? null;
        unset($counts[$others], $counts['Unknown']);
        arsort($counts);

        return array_filter([...$counts, $others => $tail, 'Unknown' => $unknown], fn ($count) => $count !== null);
    };
    $countryName = fn (string $code) => \Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code;

    $devices = $mostFirst($period->counts(CountKind::PeopleDevice));
    $deviceColors = ['Desktop' => 'var(--series-visitors)', 'Mobile' => 'var(--series-pages)', 'Tablet' => 'var(--group-bots)'];
    $list = fn (CountKind $kind) => collect($mostFirst($period->counts($kind)))->map(fn ($count, $name) => [
        'label' => match ((string) $name) { $others => __('(all others)'), 'Other' => __('Other'), default => (string) $name },
        'count' => $count,
    ])->values()->all();

    $countries = collect($mostFirst($period->counts(CountKind::PeopleCountry)))->map(fn ($count, $code) => [
        match ((string) $code) {
            'Unknown' => ['label' => __('Not recorded')],
            $others => ['label' => __('(all others)')],
            default => ['label' => $code.' · '.$countryName((string) $code), 'flag' => Labels::flag((string) $code)],
        },
        number_format($count),
        $percent($count, $people->readers),
    ])->values()->all();

    $cities = collect($mostFirst($period->counts(CountKind::PeopleCity)))->map(function ($count, $name) use ($others, $percent, $people) {
        [$code, $city, $region] = array_pad(explode(CountKind::CITY_SEPARATOR, (string) $name), 3, '');

        return [
            match ((string) $name) {
                'Unknown' => ['label' => __('Not recorded')],
                $others => ['label' => __('(all others)')],
                default => ['label' => implode(', ', array_filter([$city, $region, $code])), 'flag' => Labels::flag($code)],
            },
            number_format($count),
            $percent($count, $people->readers),
        ];
    })->values()->all();
    $citiesTotal = array_sum($period->counts(CountKind::PeopleCity));
@endphp

<x-section-frame :team="$team" :site="$site" current="places" :title="__('Places & devices')" :period="$period" :choice="$choice">
    <div class="grid items-start gap-4 lg:grid-cols-3">
        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <h3 class="mb-3 text-[14px] font-bold">{{ __('Devices') }}</h3>
            <div class="flex h-3 overflow-hidden rounded-full bg-sunk">
                @foreach ($devices as $device => $count)
                    <span title="{{ __($device) }}: {{ number_format($count) }}" style="width: {{ $people->readers > 0 ? $count / $people->readers * 100 : 0 }}%; background: {{ $deviceColors[$device] ?? 'var(--gap)' }}"></span>
                @endforeach
            </div>
            <ul class="mt-3 grid gap-1 text-[13px]">
                @foreach ($devices as $device => $count)
                    <li class="flex items-center gap-2">
                        <span class="size-2.5 rounded-[3px]" style="background: {{ $deviceColors[$device] ?? 'var(--gap)' }}"></span>
                        <x-icon :name="Labels::deviceIcon((string) $device)" class="size-3.5 text-ink-2" />
                        {{ __((string) $device) }}
                        <span class="ml-auto tabular-nums">{{ number_format($count) }} <span class="text-ink-3">({{ $percent($count, $people->readers) }})</span></span>
                    </li>
                @endforeach
            </ul>
        </section>

        <x-top-list :title="__('Browsers')" :unit="__('An in-app browser by its app')" :rows="$list(CountKind::PeopleBrowser)" :total="$people->readers" :shown="6" />

        @if (array_sum($period->counts(CountKind::PeopleSystem)) === $people->readers)
            <x-top-list :title="__('Systems')" :unit="__('An iPad asking for the desktop site counts as macOS')" :rows="$list(CountKind::PeopleSystem)" :total="$people->readers" :shown="6" />
        @else
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('Systems') }}</h3>
                <p class="py-3 text-ink-3">{{ __('Not recorded for every day of this period.') }}</p>
            </section>
        @endif
    </div>

    <x-rows-table :title="__('Countries')" :unit="__('From the log\'s country field, or from the visitor\'s address for a line without one; Not recorded when neither had it, never guessed.')"
        :columns="[[__('Country'), 'left'], [__('Visitors'), 'right'], [__('Share'), 'right']]" :rows="$countries" :shown="15">
        <x-geoip-credit class="mt-2" />
    </x-rows-table>

    <x-rows-table :title="__('Cities')" :unit="__('Each visitor\'s city at their first page, from the city file. A city is the city\'s own, never the visitor\'s street; Not recorded when the file had no city for the address, or named one in another country than the line\'s.')"
        :columns="[[__('City'), 'left'], [__('Visitors'), 'right'], [__('Share'), 'right']]" :rows="$citiesTotal === $people->readers ? $cities : []" :shown="15"
        :empty="__('Not recorded for every day of this period.')" />
</x-section-frame>
