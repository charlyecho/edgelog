@props(['team', 'site', 'current'])

@php($keep = array_filter(['days' => request('days')]))

<x-subtabs :current="$current" :tabs="[
    'visitors' => ['label' => __('Visitors'), 'href' => route('teams.sites.traffic.visitors', [$team, $site, ...$keep])],
    'sources' => ['label' => __('Sources'), 'href' => route('teams.sites.traffic.sources', [$team, $site, ...$keep])],
    'pages' => ['label' => __('Pages'), 'href' => route('teams.sites.traffic.pages', [$team, $site, ...$keep])],
    'places' => ['label' => __('Places & devices'), 'href' => route('teams.sites.traffic.places', [$team, $site, ...$keep])],
    'retention' => ['label' => __('Retention'), 'href' => route('teams.sites.traffic.retention', [$team, $site, ...$keep])],
    'time' => ['label' => __('Time'), 'href' => route('teams.sites.traffic.time', [$team, $site, ...$keep])],
]" />
