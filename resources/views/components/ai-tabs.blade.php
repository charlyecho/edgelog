@props(['team', 'site', 'current'])

@php($keep = array_filter(['days' => request('days')]))

<x-subtabs :current="$current" :tabs="[
    'assistants' => ['label' => __('Assistants'), 'href' => route('teams.sites.ai.assistants', [$team, $site, ...$keep])],
    'crawlers' => ['label' => __('Crawlers'), 'href' => route('teams.sites.ai.crawlers', [$team, $site, ...$keep])],
    'crawl' => ['label' => __('Crawl health'), 'href' => route('teams.sites.ai.crawl', [$team, $site])],
    'rules' => ['label' => __('Crawler rules'), 'href' => route('teams.sites.ai.rules', [$team, $site, ...$keep])],
    'llms' => ['label' => __('llms.txt'), 'href' => route('teams.sites.ai.llms', [$team, $site, ...$keep])],
]" />
