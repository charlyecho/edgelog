@props(['team', 'site', 'current'])

<x-subtabs :current="$current" :tabs="[
    'summary' => ['label' => __('Summary'), 'href' => route('teams.sites.show', [$team, $site])],
    'realtime' => ['label' => __('Real time'), 'href' => route('teams.sites.realtime', [$team, $site])],
]" />
