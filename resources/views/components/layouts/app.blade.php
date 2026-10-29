@props(['title', 'lede' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <x-favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-page font-sans text-ink antialiased">
    <div class="min-h-screen md:grid md:grid-cols-[236px_minmax(0,1fr)]">
        {{-- On a phone the rail is a panel the header's menu button slides in; from md up it always shows. --}}
        <div class="fixed inset-0 z-40 hidden bg-ink/30 md:hidden" data-rail-backdrop></div>
        <aside id="rail" class="fixed inset-y-0 left-0 z-50 flex w-[min(280px,85vw)] -translate-x-full flex-col border-r border-rule bg-page px-3 pt-4 pb-3.5 shadow-card transition-transform duration-200 data-[open]:translate-x-0 md:sticky md:top-0 md:z-auto md:h-screen md:w-auto md:translate-x-0 md:shadow-none md:transition-none" data-rail>
            @if ($railTeam)
                <details class="relative mb-3">
                    <summary class="flex min-h-[52px] cursor-pointer list-none items-center gap-2.5 rounded-[10px] border border-rule bg-surface px-2.5 py-2 hover:border-gap">
                        <x-tile :label="$railTeam->name" :seed="$railTeam->uuid" :src="$railTeam->iconUrl()" size="size-8 text-base" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="truncate text-[15px] font-bold">{{ $railTeam->name }}</span>
                            <span class="truncate text-xs text-ink-3">{{ config('app.name') }}</span>
                        </span>
                        <x-icon name="chevron" class="ml-auto size-4 flex-none text-ink-2" />
                    </summary>

                    <div class="absolute inset-x-0 top-[calc(100%+6px)] z-20 grid gap-0.5 rounded-[10px] border border-rule bg-surface p-1.5 shadow-card">
                        @if ($railTeams->count() > 1)
                            <p class="px-2 pt-1 pb-1 text-[11.5px] font-semibold text-ink-3">{{ __('Switch team') }}</p>
                            @foreach ($railTeams as $team)
                                <a href="{{ route('teams.servers.index', $team) }}" @class([
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2 no-underline hover:bg-sunk',
                                    'font-semibold' => $team->is($railTeam),
                                ])>
                                    <x-tile :label="$team->name" :seed="$team->uuid" :src="$team->iconUrl()" size="size-5 text-[10px]" />
                                    <span class="min-w-0 truncate">{{ $team->name }}</span>
                                    @if ($team->is($railTeam))
                                        <x-icon name="check" class="ml-auto" />
                                    @endif
                                </a>
                            @endforeach
                        @endif

                        <a href="{{ route('teams.create') }}" @class([
                            'flex min-h-9 items-center gap-2 rounded-lg px-2 text-ink-2 no-underline hover:bg-sunk',
                            'mt-1 border-t border-grid' => $railTeams->count() > 1,
                        ])>
                            <x-icon name="plus" /> {{ __('Create a team') }}
                        </a>
                    </div>
                </details>

                <nav class="grid gap-0.5">
                    <x-rail-link :href="route('teams.servers.index', $railTeam)" icon="server" :active="request()->routeIs('teams.servers.index')">{{ __('Servers') }}</x-rail-link>
                    <x-rail-link :href="route('teams.sites.index', $railTeam)" icon="globe" :active="request()->routeIs('teams.sites.index', 'teams.sites.create')">{{ __('Sites') }}</x-rail-link>
                </nav>

                {{-- The team's servers, each with its sites; the site open shows its sections. --}}
                <nav class="mt-3 -mx-1 grid min-h-0 flex-1 content-start gap-0.5 overflow-y-auto border-t border-rule px-1 pt-3" aria-label="{{ __('Servers and sites') }}">
                    @foreach ($railGroups as $group)
                        @if (count($railGroups) > 1 || $group['server'] !== null)
                            @if ($group['server'])
                                @php($isCurrent = $group['server']->is($railServer))
                                <a href="{{ route('teams.servers.show', [$railTeam, $group['server']]) }}" @if ($isCurrent) aria-current="page" @endif @class([
                                    'flex h-8 items-center gap-2 rounded-lg px-2 text-[12px] font-semibold no-underline',
                                    'mt-2' => ! $loop->first,
                                    'border border-rule bg-surface text-ink shadow-card' => $isCurrent,
                                    'text-ink-3 hover:bg-sunk hover:text-ink' => ! $isCurrent,
                                ])>
                                    <x-icon name="server" class="size-3.5" />
                                    <span class="min-w-0 truncate">{{ $group['server']->name() }}</span>
                                </a>
                            @else
                                <p @class(['flex h-8 items-center px-2 text-[12px] font-semibold text-ink-3', 'mt-2' => ! $loop->first])>{{ __('No known server') }}</p>
                            @endif
                        @endif

                        @foreach ($group['sites'] as $site)
                            @php($isCurrent = $site->is($railSite))
                            <a href="{{ route('teams.sites.show', [$railTeam, $site]) }}" @class([
                                'flex min-h-9 items-center gap-2.5 rounded-lg px-2 text-sm no-underline',
                                'ml-2' => $group['server'] !== null || count($railGroups) > 1,
                                'border border-rule bg-surface font-semibold text-ink shadow-card' => $isCurrent,
                                'text-ink-2 hover:bg-sunk hover:text-ink' => ! $isCurrent,
                            ])>
                                <x-tile :label="$site->name" :seed="$site->uuid" :src="$site->iconUrl($railTeam)" />
                                <span class="min-w-0 truncate">{{ $site->name }}</span>
                            </a>

                            @if ($isCurrent)
                                <div @class(['my-1 grid gap-0.5 border-l border-rule pl-2', 'ml-6' => $group['server'] !== null || count($railGroups) > 1, 'ml-4' => $group['server'] === null && count($railGroups) === 1])>
                                    <x-rail-link :href="route('teams.sites.show', [$railTeam, $site])" icon="chart" :active="request()->routeIs('teams.sites.show', 'teams.sites.realtime', 'teams.sites.insights')">{{ __('Overview') }}</x-rail-link>
                                    <x-rail-link :href="route('teams.sites.traffic.visitors', [$railTeam, $site])" icon="route" :active="request()->routeIs('teams.sites.traffic.*')">{{ __('Traffic') }}</x-rail-link>
                                    <x-rail-link :href="route('teams.sites.sales.users', [$railTeam, $site])" icon="users" :active="request()->routeIs('teams.sites.sales.*')">{{ __('Leads & sales') }}</x-rail-link>
                                    <x-rail-link :href="route('teams.sites.ai.assistants', [$railTeam, $site])" icon="sparkle" :active="request()->routeIs('teams.sites.ai.*')">{{ __('AI & bots') }}</x-rail-link>
                                    <x-rail-link :href="route('teams.sites.security.exposed', [$railTeam, $site])" icon="shield" :active="request()->routeIs('teams.sites.security.*')">{{ __('Security') }}</x-rail-link>
                                    <x-rail-link :href="route('teams.sites.site.health', [$railTeam, $site])" icon="pulse" :active="request()->routeIs('teams.sites.site.*')">{{ __('Site') }}</x-rail-link>
                                    @can('update', $site)
                                        <x-rail-link :href="route('teams.sites.edit', [$railTeam, $site])" icon="sliders" :active="request()->routeIs('teams.sites.edit')">{{ __('Site settings') }}</x-rail-link>
                                    @endcan
                                </div>
                            @endif
                        @endforeach
                    @endforeach
                </nav>
            @endif

            <div class="mt-auto grid gap-0.5 border-t border-rule pt-3">
                @if ($railTeam)
                    <x-rail-link :href="route('teams.edit', $railTeam)" icon="gear" :active="request()->routeIs('teams.edit')">{{ __('Team settings') }}</x-rail-link>
                @endif
                <div class="flex items-center gap-0.5">
                    <a href="{{ route('profile.show') }}" @class([
                        'flex h-[34px] min-w-0 flex-1 items-center gap-2.5 rounded-lg px-2.5 text-ink-2 no-underline hover:bg-sunk hover:text-ink',
                        'bg-surface font-semibold text-ink shadow-[inset_0_0_0_1px_var(--rule)]' => request()->routeIs('profile.*'),
                    ]) title="{{ __('Your account') }}">
                        <span class="grid size-6 flex-none place-items-center rounded-full bg-ink text-[10.5px] font-bold text-surface">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="truncate">{{ auth()->user()->name }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="grid h-[34px] w-[34px] cursor-pointer place-items-center rounded-lg text-ink-2 hover:bg-sunk hover:text-ink" title="{{ __('Sign out') }}">
                            <x-icon name="logout" />
                            <span class="sr-only">{{ __('Sign out') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="flex min-h-14 flex-wrap items-center gap-x-3 gap-y-2 border-b border-rule px-4 py-2 md:px-6">
                <button type="button" class="-ml-1.5 grid size-9 flex-none cursor-pointer place-items-center rounded-lg text-ink-2 hover:bg-sunk hover:text-ink md:hidden" aria-controls="rail" aria-expanded="false" data-rail-toggle>
                    <x-icon name="menu" class="size-5" />
                    <span class="sr-only">{{ __('Menu') }}</span>
                </button>
                <ol class="flex min-w-0 flex-1 items-center gap-1.5 text-[17px] font-bold tracking-tight md:flex-none">
                    @isset($trail)
                        {{ $trail }}
                    @endisset
                    <li class="truncate">{{ $title }}</li>
                </ol>
                @isset($toolbar)
                    <div class="flex w-full flex-wrap items-center gap-2 md:ml-4 md:w-auto">{{ $toolbar }}</div>
                @endisset
                @isset($actions)
                    <div class="ml-auto flex items-center gap-2">{{ $actions }}</div>
                @endisset
            </header>

            <main class="px-4 py-5 md:px-6 md:py-6">
                @if ($lede)
                    <p class="mb-6 max-w-[92ch] text-ink-2">{{ $lede }}</p>
                @endif

                <x-flash />

                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- The side panel a [data-drawer] link opens: the page it links to, in panel form. --}}
    <div class="fixed inset-0 z-40 hidden" data-drawer-root aria-hidden="true">
        <div class="absolute inset-0 bg-ink/30" data-drawer-close></div>
        <aside class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col border-l border-rule bg-page shadow-card" role="dialog" aria-modal="true" aria-label="{{ __('Details') }}">
            <div class="flex items-center justify-between border-b border-rule px-4 py-3">
                <a href="#" class="text-[13px] text-ink-2 underline hover:text-ink" data-drawer-page target="_blank" rel="noopener">{{ __('Open as a page') }}</a>
                <button type="button" class="grid size-8 cursor-pointer place-items-center rounded-lg text-ink-2 hover:bg-sunk hover:text-ink" data-drawer-close>
                    <span aria-hidden="true">✕</span><span class="sr-only">{{ __('Close') }}</span>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4" data-drawer-body></div>
        </aside>
    </div>
</body>
</html>
