@php
    $hosts = $security->hosts();
    $answered = $security->figure('answered_page');
    $possible = $security->figure('possible');
    $unknown = $security->figure('unknown');
    $exposures = collect($security->exposures())->filter(fn ($exposure) => $show === 'both' || $exposure['verdict'] === $show)->values();
    $hostName = fn (string $host) => $host === '' ? $site->host() : $host;
    $bytes = fn (?int $size) => $size === null ? __('not recorded') : trans_choice(':count byte|:count bytes', $size, ['count' => number_format($size)]);
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="exposed" :title="__('Is anything exposed?')" :period="$period" :choice="$choice"
    :lede="__('Did the server hand a scanner a real file? Every probe for a file answered with a page, set against the host\'s own harmless page.')">
    <x-security-partial :security="$security" />

    @if ($possible > 0)
        <x-notice tone="bad" :title="trans_choice(':count possible exposure in this period|:count possible exposures in this period', $possible)">
            {{ __('A probe for a file was answered with a page unlike the host\'s fallback page. Open each one below and check what the server sends for it: the log records a size, never the content.') }}
        </x-notice>
    @elseif ($answered > 0)
        <x-notice :title="__('Nothing exposed in this period')">
            {{ trans_choice(':count probe was answered with a page; it was the host\'s harmless fallback page.|:count probes were answered with a page; every one was the host\'s harmless fallback page or empty.', $answered - $unknown) }}
            @if ($unknown > 0)
                {{ trans_choice(':count more had no size in the log: it cannot be told.|:count more had no size in the log: they cannot be told.', $unknown) }}
            @endif
        </x-notice>
    @else
        <x-notice :title="__('Nothing exposed in this period')">{{ __('No probe for a file was answered with a page.') }}</x-notice>
    @endif

    <x-stat-row :stats="[
        [__('Scanner probes'), $security->figure('probes'), __('Requests for files and panels a site does not serve to readers: .env, .git, wp-login.php, phpMyAdmin...')],
        [__('Answered with a page'), $answered, __('Probes for a file the server answered 2xx.')],
        [__('Harmless page'), $security->figure('shell') + $security->figure('empty'), __('Answered with the host\'s fallback page (the size it gave several other probes that day), or empty.')],
        [__('Possible exposures'), $possible, __('Answered with another size: check it.'), $possible > 0 ? 'bad' : null],
        [__('Can\'t tell'), $unknown, __('The log line has no size: never called safe.')],
    ]" />

    <section class="grid gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[13px] font-semibold text-ink-2">{{ __('Show') }}</span>
            <x-segmented name="show" :current="$show" default="both" :options="['both' => __('Both'), 'possible' => __('Possible exposures'), 'unknown' => __('Can\'t tell')]" />
        </div>
        <x-rows-table :columns="[[__('When'), 'left'], [__('Host'), 'left'], [__('Path'), 'left'], [__('Answered'), 'right'], [__('Size'), 'right']]"
            :rows="$exposures->map(fn ($exposure) => [
                \Carbon\CarbonImmutable::createFromTimestamp($exposure['at'], $site->timezone)->translatedFormat('j M H:i'),
                $hostName($exposure['host']),
                ['label' => $exposure['path'], 'mono' => true, 'note' => $exposure['verdict'] === 'possible' ? __('possible exposure') : __('can\'t tell')],
                (string) $exposure['status'],
                $bytes($exposure['size']),
            ])->all()" :shown="20" :empty="__('Every probe answered with a page got the host\'s harmless page.')" />
    </section>

    <x-rows-table :title="__('Each host\'s answers')" :unit="__('Each host has its own fallback page: the size it answered most probe paths with. The size the log records is the size sent, after compression, so the same page can differ between two hosts.')"
        :columns="[[__('Host'), 'left'], [__('Sent to'), 'left'], [__('Answered 2xx'), 'right'], [__('Its fallback page'), 'right'], [__('Harmless'), 'right'], [__('Possible'), 'right'], [__('Can\'t tell'), 'right']]"
        :rows="collect($hosts)->map(fn ($row, $host) => [
            $hostName((string) $host),
            $row['bare_ip'] ? __('the bare IP') : __('the site\'s name'),
            number_format($row['answered']),
            $row['shells'] === [] ? __('none found') : implode(', ', array_map($bytes, $row['shells'])),
            number_format($row['shell'] + $row['empty']),
            number_format($row['possible']),
            number_format($row['unknown']),
        ])->values()->all()" :shown="10" :empty="__('No probe for a file was answered with a page.')" />

    <p class="text-xs text-ink-3">{{ __('A path your site really has (its own /admin/, say) is not a probe once it is listed in Site settings, Paths.') }}</p>
</x-section-frame>
