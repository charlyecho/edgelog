@php
    use App\Analytics\Stack\StackDetector;

    $name = fn (string $product) => StackDetector::NAMES[$product] ?? $product;
    $attention = $stack?->attention() ?? [];
    $hidden = collect($attention)->where('status', 'check_version')->pluck('product')->unique()->filter(fn ($product) => isset(StackDetector::COMMANDS[$product]));
    $badges = [
        'eol' => [__('Branch no longer supported'), 'bg-bad text-white'],
        'update' => [__('Newer release out'), 'bg-warn text-white'],
        'check_version' => [__('Check your version'), 'bg-sunk text-ink-2'],
    ];
    $statusWords = [
        'current' => [__('newest of its branch'), 'text-good'],
        'update' => [__('newer release out'), 'text-warn'],
        'eol' => [__('branch no longer supported'), 'text-bad'],
        'check_version' => [__('version not shown'), 'text-ink-3'],
        'not_listed' => [__('branch not listed on endoflife.date'), 'text-ink-3'],
        'shown' => ['', ''],
    ];
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="stack" :title="__('Your stack')"
    :lede="__('What runs the site, and is each part still supported and up to date? Read from outside once a day: the period bar does not apply here.')">
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-rule bg-surface p-4 text-[13px] shadow-card">
        <p class="text-ink-2">
            @if ($stack === null)
                {{ __('Not checked yet: the site is read once a day.') }}
            @else
                {{ __('As of :when · each release set against its branch on endoflife.date.', ['when' => $stack->checked_at->setTimezone($site->timezone)->translatedFormat('j M H:i')]) }}
            @endif
        </p>
        @can('update', $site)
            <form method="POST" action="{{ route('teams.sites.security.stack.refresh', [$team, $site]) }}">
                @csrf
                <x-button variant="secondary" size="sm">{{ __('Check again') }}</x-button>
            </form>
        @endcan
    </section>

    @if ($stack !== null)
        @if ($attention === [])
            <x-notice :title="__('Nothing to update')">{{ __('Every version the server shows is the newest of a supported branch.') }}</x-notice>
        @endif

        @foreach (collect($attention)->where('status', '!=', 'check_version') as $item)
            <article class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <span class="rounded px-1.5 py-0.5 text-[11px] font-bold {{ $badges[$item['status']][1] }}">{{ $badges[$item['status']][0] }}</span>
                <h3 class="mt-2 text-[15px] font-bold">{{ __(':product :version on :host', ['product' => $name($item['product']), 'version' => $item['version'], 'host' => $item['host']]) }}</h3>
                <p class="mt-1 text-[13px] text-ink-2">
                    @if ($item['status'] === 'eol')
                        {{ __('Its branch :cycle stopped getting fixes:eol, security fixes included. Move to a supported branch: :newest is the newest release.', ['cycle' => $item['cycle'], 'eol' => $item['eol'] ? ' '.__('on :date', ['date' => $item['eol']]) : '', 'newest' => $item['newest'] ?? '—']) }}
                    @else
                        {{ __(':latest is out in the :cycle branch. Releases within a branch carry bug and security fixes: update, then restart the service.', ['latest' => $item['latest'], 'cycle' => $item['cycle']]) }}
                    @endif
                </p>
            </article>
        @endforeach

        <section class="grid gap-3">
            <h3 class="text-[15px] font-bold">{{ __('What serves the site') }}</h3>
            <div class="grid items-start gap-4 lg:grid-cols-3">
                @foreach ($stack->hosts as $host)
                    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                        <p class="mb-2"><span class="font-bold">{{ $host['host'] }}</span> <span class="text-xs text-ink-3">{{ $host['role'] === 'site' ? __('the site') : __('content origin') }}</span></p>
                        @if ($host['error'])
                            <p class="text-[13px] text-bad">{{ __('Could not be read: :error', ['error' => $host['error']]) }}</p>
                        @elseif ($host['products'] === [])
                            <p class="text-[13px] text-ink-3">{{ __('The server says nothing about what runs it.') }}</p>
                        @endif
                        <dl class="grid gap-2 text-[13px]">
                            @foreach ($host['products'] as $product => $found)
                                <div>
                                    <dt class="font-semibold">{{ $name($product) }}</dt>
                                    <dd class="text-ink-2">
                                        {{ $found['version'] ?? __('not shown by the server') }}
                                        <span class="text-ink-3">({{ $found['source'] }})</span>
                                        @if ($statusWords[$found['status']][0] !== '' && $found['version'] !== null)
                                            · <span class="{{ $statusWords[$found['status']][1] }}">{{ $statusWords[$found['status']][0] }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($host['login'] === 'open')
                            <p class="mt-3 text-[13px] font-semibold text-bad">{{ __('Login page open to the internet: /wp-login.php answers anyone.') }}</p>
                        @elseif ($host['login'] === 'closed')
                            <p class="mt-3 text-[13px] text-good">{{ __('Login page not open to the internet (/wp-login.php refused).') }}</p>
                        @endif
                    </section>
                @endforeach
            </div>
        </section>

        @if ($hidden->isNotEmpty())
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('For your server admin: versions the server does not show') }}</h3>
                <p class="mb-3 text-xs text-ink-3">{{ __('Hiding a version is good practice; run these on the server to know whether it needs updating.') }}</p>
                <ul class="grid gap-1.5 text-[13px]">
                    @foreach ($hidden as $product)
                        <li class="flex flex-wrap items-center gap-2">
                            <span class="w-28 font-semibold">{{ $name($product) }}</span>
                            <code class="rounded-md bg-sunk px-1.5 py-0.5 font-mono text-xs">{{ StackDetector::COMMANDS[$product] }}</code>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="text-xs text-ink-3">{{ __('This says whether each release is the newest of its branch and whether its branch is still supported, from endoflife.date. It is not a vulnerability scan, and Edgelog does not detect or block an attack on these flaws.') }}</p>
    @endif
</x-section-frame>
