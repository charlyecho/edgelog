@php
    use App\Analytics\Health\HealthCollector;
    use App\Analytics\Labels;
    use App\Analytics\Live\RecentRequests;
    use App\Enums\TrafficGroup;

    $local = fn (int $time, string $format = 'H:i') => \Carbon\CarbonImmutable::createFromTimestamp($time, $site->timezone)->format($format);
    $statusTone = fn (int $status) => match (HealthCollector::statusClass($status)) {
        '2xx' => 'text-good',
        '3xx' => 'text-[var(--group-people)]',
        '404', '4xx' => 'text-warn',
        '5xx' => 'text-bad',
        default => 'text-ink-3',
    };
    $shows = [
        'all' => __('Everything'),
        'no_bots' => __('Hide bots and crawlers'),
        'people' => __('Only real people'),
        'errors' => __('Only errors'),
        'ai' => __('Only AI'),
    ];
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="requests" :title="__('Requests')"
    :lede="__('Every request of the last :hours hours, newest first, with the group and the rule that decided it. Read from the log as it is now, with the same rules as the daily count; nothing of it is kept.', ['hours' => RecentRequests::HOURS])">
    <div class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-ink-2" data-live-refresh="{{ $countedAt === null ? 15 : 120 }}">
        <p>
            {{ __('Last :hours hours · the period bar does not apply to this log.', ['hours' => RecentRequests::HOURS]) }}
            @if ($countedAt === null)
                {{ __('Being counted: the queue is reading the log, and this page shows it as soon as it is done.') }}
            @else
                {{ __('Counted at :time (:timezone).', ['time' => $local($countedAt, 'H:i:s'), 'timezone' => $site->timezone]) }}
            @endif
            <span data-live-state>{{ __('Refreshed every 2 minutes.') }}</span>
        </p>
        <button type="button" class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-rule bg-surface px-3 font-semibold text-ink hover:border-ink-3" data-live-pause data-pause="{{ __('Pause') }}" data-resume="{{ __('Resume') }}" data-paused="{{ __('Paused.') }}" data-running="{{ __('Refreshed every 2 minutes.') }}">
            {{ __('Pause') }}
        </button>
    </div>

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <p class="flex flex-wrap items-baseline gap-2">
            <span class="text-[26px] font-bold tabular-nums">{{ number_format($log['total']) }}</span>
            <span class="text-[13px] text-ink-2">{{ __('requests from :from to :to', ['from' => $local($log['from']), 'to' => $local($log['until'])]) }}</span>
        </p>
        <div class="mt-3 flex h-3 gap-0.5 overflow-hidden rounded-full bg-sunk" role="img" aria-label="{{ __('Requests by group') }}">
            @foreach (TrafficGroup::cases() as $group)
                @if ($log['by_group'][$group->value] > 0)
                    <span style="flex-grow: {{ $log['by_group'][$group->value] }}; background: var(--group-{{ $group->value }})" title="{{ $group->label() }}: {{ number_format($log['by_group'][$group->value]) }}"></span>
                @endif
            @endforeach
        </div>
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
            @foreach (TrafficGroup::cases() as $group)
                <li class="flex items-center gap-1.5"><span class="size-2 rounded-[2px]" style="background: var(--group-{{ $group->value }})"></span>{{ $group->label() }} <span class="font-semibold text-ink tabular-nums">{{ number_format($log['by_group'][$group->value]) }}</span></li>
            @endforeach
        </ul>
        @if ($log['total'] > count($log['rows']))
            <p class="mt-2 text-xs text-ink-3">{{ __('Only the newest :cap requests are listed.', ['cap' => number_format(RecentRequests::CAP)]) }}</p>
        @endif
    </section>

    <div class="grid gap-3">
        <x-segmented name="show" :current="$filters['show']" default="all" :options="$shows" class="max-w-full justify-self-start overflow-x-auto" />

        <form method="GET" class="flex flex-wrap items-center gap-2" role="search">
            @if ($filters['show'] !== 'all')
                <input type="hidden" name="show" value="{{ $filters['show'] }}">
            @endif
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Search address, path, rule, referrer') }}" aria-label="{{ __('Search address, path, rule, referrer') }}"
                class="h-8 w-72 max-w-full rounded-lg border border-rule bg-surface px-2.5 text-[13px] focus:border-ink-3 focus:outline-none">
            <x-select name="group" :aria-label="__('Group')">
                <option value="">{{ __('Every group') }}</option>
                @foreach (TrafficGroup::cases() as $group)
                    <option value="{{ $group->value }}" @selected($filters['group'] === $group)>{{ $group->label() }}</option>
                @endforeach
            </x-select>
            <x-select name="status" :aria-label="__('Status')">
                <option value="">{{ __('Every status') }}</option>
                @foreach (HealthCollector::STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status === 'other' ? __('other') : $status }}</option>
                @endforeach
            </x-select>
            <x-select name="method" :aria-label="__('Method')">
                <option value="">{{ __('Every method') }}</option>
                @foreach ($methods as $method)
                    @if ($method !== '')
                        <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ $method }}</option>
                    @endif
                @endforeach
            </x-select>
            <x-button type="submit" variant="secondary" size="sm">{{ __('Filter') }}</x-button>
            @if ($filters['q'] !== '' || $filters['group'] || $filters['status'] || $filters['method'])
                <a href="{{ request()->url().($filters['show'] === 'all' ? '' : '?show='.$filters['show']) }}" class="text-[13px] text-ink-2">{{ __('Clear') }}</a>
            @endif
        </form>
    </div>

    <section class="overflow-hidden rounded-[10px] border border-rule bg-surface shadow-card">
        @if ($rows->isEmpty())
            <p class="px-4 py-6 text-ink-3">{{ $log['total'] === 0 ? __('No request reached the log in the last :hours hours.', ['hours' => RecentRequests::HOURS]) : __('No request matches these filters.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-[13px] tabular-nums">
                    <thead class="border-b border-rule text-left text-ink-2">
                        <tr>
                            <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('Time') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Request') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Group, and the rule that decided it') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('From') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Referrer') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Device') }}</th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap">{{ __('Server time') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-grid align-top">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-xs whitespace-nowrap">{{ $local($row['at'], 'H:i:s') }}</td>
                                <td class="px-3 py-1.5"><span @class(['font-mono text-xs font-semibold', $statusTone($row['status'])])>{{ $row['status'] }}</span></td>
                                <td class="max-w-72 px-3 py-1.5">
                                    <span class="block truncate font-mono text-xs" title="{{ $row['method'] }} {{ $row['path'] }}">
                                        @if ($row['method'] !== 'GET')
                                            <span class="text-ink-3">{{ $row['method'] ?: '–' }}</span>
                                        @endif
                                        {{ $row['path'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-1.5">
                                    <span class="flex items-center gap-1.5 border-l-2 pl-2" style="border-color: var(--group-{{ $row['group'] }})">
                                        <span>{{ RecentRequests::rule($row) }}</span>
                                        @if ($row['page'])
                                            <span class="rounded bg-sunk px-1 text-[11px] text-ink-2">{{ __('page view') }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    <span class="flex items-center gap-1.5">
                                        @if ($row['country'] !== '')
                                            <span aria-hidden="true">{{ Labels::flag($row['country']) }}</span>
                                        @endif
                                        <a href="{{ route('teams.sites.security.ip', [$team, $site, 'ip' => $row['address']]) }}" data-drawer class="font-mono text-xs text-ink underline decoration-rule underline-offset-2 hover:decoration-ink">{{ $row['address'] }}</a>
                                    </span>
                                </td>
                                <td class="max-w-56 px-3 py-1.5">
                                    <span class="block truncate text-xs text-ink-2" title="{{ $row['referrer'] }}">{{ $row['referrer'] === '' ? '–' : $row['referrer'] }}</span>
                                </td>
                                <td class="max-w-56 px-3 py-1.5 text-xs text-ink-2">
                                    <span class="block truncate" title="{{ $row['user_agent'] }}">{{ $row['agent'] }}, {{ $row['system'] }}, {{ __($row['device']) }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-right whitespace-nowrap">{{ $row['ms'] === null ? '–' : __(':ms ms', ['ms' => number_format($row['ms'])]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($rows->hasPages())
        <div>{{ $rows->links() }}</div>
    @endif
</x-section-frame>
