@props(['team', 'site', 'current'])

@php($keep = array_filter(['days' => request('days')]))

<div class="relative">
    <x-subtabs :current="$current" :tabs="[
        'exposed' => ['label' => __('Is anything exposed?'), 'href' => route('teams.sites.security.exposed', [$team, $site, ...$keep])],
        'stack' => ['label' => __('Your stack'), 'href' => route('teams.sites.security.stack', [$team, $site])],
        'scanners' => ['label' => __('Who is scanning us?'), 'href' => route('teams.sites.security.scanners', [$team, $site, ...$keep])],
        'attacks' => ['label' => __('Attack attempts'), 'href' => route('teams.sites.security.attacks', [$team, $site, ...$keep])],
        'fake' => ['label' => __('Fake browsers'), 'href' => route('teams.sites.security.fake', [$team, $site, ...$keep])],
        'block' => ['label' => __('Block list'), 'href' => route('teams.sites.security.block', [$team, $site, ...$keep])],
    ]" />
    <form method="GET" action="{{ route('teams.sites.security.ip', [$team, $site]) }}" class="absolute top-0 right-0 hidden items-center gap-1.5 xl:flex" role="search">
        @foreach ($keep as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <input type="search" name="ip" value="{{ request()->routeIs('teams.sites.security.ip') ? request('ip') : '' }}" placeholder="{{ __('Find an IP') }}" aria-label="{{ __('Find an IP') }}"
            class="h-8 w-48 rounded-lg border border-rule bg-surface px-2.5 font-mono text-[13px] focus:border-ink-3 focus:outline-none">
    </form>
</div>
