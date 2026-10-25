@props(['team', 'site', 'current'])

@php($keep = array_filter(['days' => request('days')]))

<x-subtabs :current="$current" :tabs="[
    'users' => ['label' => __('Users'), 'href' => route('teams.sites.sales.users', [$team, $site, ...$keep])],
]" />
