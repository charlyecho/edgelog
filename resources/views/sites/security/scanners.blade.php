@php
    use App\Analytics\Labels;
    use App\Analytics\Security\SecurityNames;
    use App\Enums\CountKind;

    $labels = SecurityNames::categoryLabels();
    $categories = $period->counts(CountKind::SecurityCategory);
    arsort($categories);
    $probes = $security->figure('probes');
    $signWords = fn (string $sign) => match ($sign) {
        'bare_ip' => __('Sent to the bare IP'),
        'forged_referrer' => __('Forged referrer'),
        default => __(ucfirst($sign)),
    };
    $answered = fn (array $answered) => collect($answered)->sortKeys()->map(fn ($count, $class) => "{$class} {$count}")->implode(', ');
    $lookedFor = function (array $categories) use ($labels) {
        $names = array_map(fn ($category) => $labels[$category] ?? $category, array_keys($categories));

        return implode(', ', array_slice($names, 0, 2)).(count($names) > 2 ? ' +'.(count($names) - 2) : '');
    };
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="scanners" :title="__('Who is scanning us?')" :period="$period" :choice="$choice"
    :lede="__('Which addresses probed the site for files and panels it does not have, and what did the server answer?')">
    <x-security-partial :security="$security" />

    <x-stat-row :stats="[
        [__('Scanner probes'), $probes, __('Requests for files and panels a site does not serve to readers.')],
        [__('Scanners'), $security->figure('scanners'), __('An address and browser with at least one probe, counted once a day.')],
        [__('Addresses'), $security->figure('addresses'), __('Their addresses, counted once a day.')],
        [__('Sent to the bare IP'), $security->figure('probes_bare_ip'), __('Probes sent to the server\'s address instead of the site\'s name: the server answers them with its default site. Only seen when the log records the host.')],
        [__('Forged referrers'), $security->figure('forged_referrers'), __('Suspects that claimed to come from one of the site\'s pages but never loaded a file of it: they never drew a page.')],
    ]" />

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]">
        <span class="font-semibold text-ink-2">{{ __('Looked for') }}</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach (['' => __('Anything'), ...array_intersect_key($labels, $categories)] as $key => $label)
                <a href="{{ request()->fullUrlWithQuery(['category' => $key === '' ? null : $key]) }}" @class([
                    'inline-flex h-7 items-center rounded-full border px-2.5 no-underline',
                    'border-ink bg-ink text-surface' => ($category ?? '') === $key,
                    'border-rule bg-surface text-ink hover:border-ink-3' => ($category ?? '') !== $key,
                ])>{{ $label }}</a>
            @endforeach
        </div>
        <x-segmented name="show" :current="$everyone ? 'all' : 'scanners'" default="scanners" :options="['scanners' => __('Scanners'), 'all' => __('Every suspect')]" />
    </div>

    <x-top-list :title="__('What they looked for')" :unit="__('Each probe once, by the first kind it matches')" :rows="collect($categories)->map(fn ($count, $key) => ['label' => $labels[$key] ?? $key, 'count' => $count])->values()->all()"
        :total="$probes" tint="var(--bad)" :shown="8" />

    <x-rows-table :title="$everyone ? __('Every suspect') : __('Scanners')" :unit="__('By address, worst first. Addresses are kept :days days.', ['days' => config('analytics.security.kept_days')])"
        :columns="[[__('Address'), 'left'], [__('Country'), 'left'], [__('First seen'), 'left'], [__('Requests'), 'right'], [__('Probes'), 'right'], [__('Looked for'), 'left'], [__('Answered'), 'left'], [__('Signs'), 'left']]"
        :rows="collect($rows)->map(fn ($row) => [
            ['label' => $row['address'], 'mono' => true, 'href' => route('teams.sites.security.ip', [$team, $site, 'ip' => $row['address'], 'days' => $choice]), 'drawer' => true],
            $row['country'] === '' ? ['label' => __('Not recorded')] : ['label' => $row['country'], 'flag' => Labels::flag($row['country'])],
            $row['first_seen_at']->setTimezone($site->timezone)->translatedFormat('j M H:i'),
            number_format($row['requests']),
            number_format($row['probes']),
            $lookedFor($row['categories']),
            $answered($row['answered']),
            implode(', ', array_map($signWords, $row['signs'])),
        ])->all()" :shown="25" :empty="__('No scanner in this period.')" />
</x-section-frame>
