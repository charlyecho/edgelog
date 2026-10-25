@php
    use App\Analytics\Labels;
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="block" :title="__('Block list')" :period="$period" :choice="$choice"
    :lede="__('Which addresses and networks should we block, and how?')">
    <x-security-partial :security="$security" />

    <x-notice tone="warn" :title="__('Review before blocking')">
        {{ __('A shared address (an office, a mobile network, a VPN) or a CDN can carry real visitors too, and a scanner moves on to a new address within days. Blocking at the web server or at Cloudflare stops the requests; it does not fix a file that answered when it should not (Is anything exposed?).') }}
    </x-notice>

    <div class="flex flex-wrap gap-2">
        @foreach (['nginx' => __('nginx deny lines'), 'cloudflare' => __('Cloudflare IP list (CSV)'), 'plain' => __('Plain list')] as $format => $label)
            <x-button variant="secondary" size="sm" :href="route('teams.sites.security.block.export', [$team, $site, $format, 'days' => $choice])">{{ $label }}</x-button>
        @endforeach
    </div>

    <x-rows-table :title="__('Networks with two or more of them')" :unit="__('Their /24 (IPv4) or /48 (IPv6).')"
        :columns="[[__('Network'), 'left'], [__('Addresses'), 'right'], [__('Probes'), 'right'], [__('Attack attempts'), 'right']]"
        :rows="collect($networks)->map(fn ($network, $name) => [['label' => $name, 'mono' => true], number_format($network['addresses']), number_format($network['probes']), number_format($network['attempts'])])->values()->all()"
        :shown="10" :empty="__('No network holds two of them.')" />

    <x-rows-table :title="__('Addresses')" :unit="__('Every address that probed or attacked, ranked by probes, then attack attempts. Addresses are kept :days days.', ['days' => config('analytics.security.kept_days')])"
        :columns="[[__('Address'), 'left'], [__('Country'), 'left'], [__('Probes'), 'right'], [__('Attack attempts'), 'right'], [__('Days seen'), 'right']]"
        :rows="collect($addresses)->map(fn ($row) => [
            ['label' => $row['address'], 'mono' => true, 'href' => route('teams.sites.security.ip', [$team, $site, 'ip' => $row['address'], 'days' => $choice]), 'drawer' => true],
            $row['country'] === '' ? ['label' => __('Not recorded')] : ['label' => $row['country'], 'flag' => Labels::flag($row['country'])],
            number_format($row['probes']),
            number_format($row['attempts']),
            number_format($row['days']),
        ])->all()" :shown="25" :empty="__('No address probed or attacked the site in this period.')">
        <p class="mt-2 text-xs text-ink-3">{{ __('The exports hold every row, networks first.') }}</p>
    </x-rows-table>
</x-section-frame>
