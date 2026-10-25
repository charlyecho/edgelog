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
        <aside class="flex flex-col border-b border-rule bg-page px-3 pt-4 pb-3.5 md:sticky md:top-0 md:h-screen md:border-r md:border-b-0">
            @if ($railTeam)
                <details class="relative mb-3">
                    <summary class="flex min-h-[52px] cursor-pointer list-none items-center gap-2.5 rounded-[10px] border border-rule bg-surface px-2.5 py-2 hover:border-gap">
                        @if ($railSite)
                            <x-tile :label="$railSite->name" :seed="$railSite->uuid" size="size-8 text-base" />
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="truncate text-[15px] font-bold">{{ $railSite->name }}</span>
                                <span class="truncate text-xs text-ink-3">{{ $railTeam->name }}</span>
                            </span>
                        @else
                            <x-tile :label="$railTeam->name" :seed="$railTeam->uuid" size="size-8 text-base" />
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="truncate text-[15px] font-bold">{{ $railTeam->name }}</span>
                                <span class="truncate text-xs text-ink-3">{{ config('app.name') }}</span>
                            </span>
                        @endif
                        <x-icon name="chevron" class="ml-auto size-4 flex-none text-ink-2" />
                    </summary>

                    <div class="absolute inset-x-0 top-[calc(100%+6px)] z-20 grid gap-0.5 rounded-[10px] border border-rule bg-surface p-1.5 shadow-card">
                        @foreach ($railSites as $site)
                            <a href="{{ route('teams.sites.show', [$railTeam, $site]) }}" @class([
                                'flex min-h-10 items-center gap-2.5 rounded-lg px-2 no-underline hover:bg-sunk',
                                'bg-sunk font-semibold' => $site->is($railSite),
                            ])>
                                <x-tile :label="$site->name" :seed="$site->uuid" />
                                <span class="min-w-0 truncate">{{ $site->name }}</span>
                                @if ($site->is($railSite))
                                    <x-icon name="check" class="ml-auto" />
                                @endif
                            </a>
                        @endforeach

                        <a href="{{ route('teams.sites.index', $railTeam) }}" @class([
                            'flex min-h-10 items-center rounded-lg px-2 text-ink-2 no-underline hover:bg-sunk',
                            'mt-1 border-t border-grid' => $railSites->isNotEmpty(),
                        ])>{{ __('All sites') }}</a>

                        @if ($railTeams->count() > 1)
                            <p class="mt-1 border-t border-grid px-2 pt-2 pb-1 text-[11.5px] font-semibold text-ink-3">{{ __('Switch team') }}</p>
                            @foreach ($railTeams as $team)
                                <a href="{{ route('teams.sites.index', $team) }}" @class([
                                    'flex min-h-9 items-center gap-2.5 rounded-lg px-2 no-underline hover:bg-sunk',
                                    'font-semibold' => $team->is($railTeam),
                                ])>
                                    <x-tile :label="$team->name" :seed="$team->uuid" size="size-5 text-[10px]" />
                                    <span class="min-w-0 truncate">{{ $team->name }}</span>
                                    @if ($team->is($railTeam))
                                        <x-icon name="check" class="ml-auto" />
                                    @endif
                                </a>
                            @endforeach
                        @endif

                        <a href="{{ route('teams.create') }}" class="mt-1 flex min-h-9 items-center gap-2 rounded-lg border-t border-grid px-2 text-ink-2 no-underline hover:bg-sunk">
                            <x-icon name="plus" /> {{ __('Create a team') }}
                        </a>
                    </div>
                </details>

                <nav class="grid gap-0.5">
                    @if ($railSite)
                        <x-rail-link :href="route('teams.sites.show', [$railTeam, $railSite])" icon="chart" :active="request()->routeIs('teams.sites.show', 'teams.sites.realtime', 'teams.sites.insights')">{{ __('Overview') }}</x-rail-link>
                        <x-rail-link :href="route('teams.sites.traffic.visitors', [$railTeam, $railSite])" icon="route" :active="request()->routeIs('teams.sites.traffic.*')">{{ __('Traffic') }}</x-rail-link>
                        <x-rail-link :href="route('teams.sites.sales.users', [$railTeam, $railSite])" icon="users" :active="request()->routeIs('teams.sites.sales.*')">{{ __('Leads & sales') }}</x-rail-link>
                        <x-rail-link :href="route('teams.sites.ai.assistants', [$railTeam, $railSite])" icon="sparkle" :active="request()->routeIs('teams.sites.ai.*')">{{ __('AI & bots') }}</x-rail-link>
                        <x-rail-link :href="route('teams.sites.security.exposed', [$railTeam, $railSite])" icon="shield" :active="request()->routeIs('teams.sites.security.*')">{{ __('Security') }}</x-rail-link>
                        <x-rail-link :href="route('teams.sites.site.health', [$railTeam, $railSite])" icon="pulse" :active="request()->routeIs('teams.sites.site.*')">{{ __('Site') }}</x-rail-link>
                        @can('update', $railSite)
                            <x-rail-link :href="route('teams.sites.edit', [$railTeam, $railSite])" icon="sliders" :active="request()->routeIs('teams.sites.edit')">{{ __('Site settings') }}</x-rail-link>
                        @endcan
                    @else
                        <x-rail-link :href="route('teams.sites.index', $railTeam)" icon="globe" :active="request()->routeIs('teams.sites.index', 'teams.sites.create')">{{ __('Sites') }}</x-rail-link>
                    @endif
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
            <header class="flex min-h-14 items-center gap-3 border-b border-rule px-6">
                <ol class="flex min-w-0 items-center gap-1.5 text-[17px] font-bold tracking-tight">
                    @isset($trail)
                        {{ $trail }}
                    @endisset
                    <li class="truncate">{{ $title }}</li>
                </ol>
                @isset($toolbar)
                    <div class="ml-4 flex flex-wrap items-center gap-2">{{ $toolbar }}</div>
                @endisset
                @isset($actions)
                    <div class="ml-auto flex items-center gap-2">{{ $actions }}</div>
                @endisset
            </header>

            <main class="px-6 py-6">
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
