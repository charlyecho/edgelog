@props(['team', 'site', 'current'])

@php($keep = array_filter(['days' => request('days')]))

<x-subtabs :current="$current" :tabs="[
    'health' => ['label' => __('Health'), 'href' => route('teams.sites.site.health', [$team, $site, ...$keep])],
    'requests' => ['label' => __('Requests'), 'href' => route('teams.sites.site.requests', [$team, $site])],
    'statuses' => ['label' => __('Status codes'), 'href' => route('teams.sites.site.statuses', [$team, $site, ...$keep])],
    'not-found' => ['label' => __('Not found'), 'href' => route('teams.sites.site.not-found', [$team, $site, ...$keep])],
    'speed' => ['label' => __('Speed'), 'href' => route('teams.sites.site.speed', [$team, $site, ...$keep])],
    'quality' => ['label' => __('Data quality'), 'href' => route('teams.sites.site.quality', [$team, $site, ...$keep])],
]" />
