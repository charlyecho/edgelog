@props(['team', 'site', 'current'])

<x-subtabs :current="$current" :tabs="[
    'visitors' => ['label' => __('Visitors'), 'href' => route('teams.sites.traffic.visitors', [$team, $site])],
]" />
