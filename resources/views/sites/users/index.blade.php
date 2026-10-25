@php
    use App\Analytics\Labels;
    use App\Enums\SourceType;
    use App\Integrations\Money;

    $sourceLabel = fn (?string $type) => $type === null ? null : (SourceType::tryFrom($type)?->label() ?? $type);
    $sortLink = fn (string $key, string $label) => ['key' => $key, 'label' => $label];
    $sorts = ['pages' => __('Pages read'), 'days' => __('Days seen'), 'last' => __('Last seen'), 'first' => __('First seen')];
@endphp

<x-section-frame section="sales" :team="$team" :site="$site" current="users" :title="__('Users')" :period="$period" :choice="$choice"
    :lede="__('The signed-in users your app names in the log (the uid= field) who were seen in the period: when each was first seen and where from, the days they came back, the pages they read, and what they paid.')">

    <x-stat-row :stats="[
        [__('Users seen'), $activeUsers, __('Distinct users seen on the verified days of the period: someone seen on several days counts once.')],
        [__('New users'), $newUsers, __('Users first seen in the period: their first signed-in visit kept.')],
        [__('Signed-in visits'), (int) $period->verified()->sum('signed_in_readers'), __('Visits that read a page while signed in.')],
    ]" />

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <div class="mb-3 flex flex-wrap items-center gap-3 text-[13px]">
            <form method="GET" class="flex items-center gap-2">
                @foreach (request()->except(['q', 'page']) as $name => $value)
                    @if (is_string($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Find a uid') }}" class="h-8 w-56 rounded-lg border border-rule bg-surface px-2.5 font-mono text-[13px] focus:border-ink-3 focus:outline-none">
            </form>
            <x-segmented name="new" :current="$onlyNew ? '1' : ''" default="" :options="['' => __('Everyone'), '1' => __('New in the period')]" />
            <span class="ml-auto text-ink-2">{{ __('Sort by') }}</span>
            <x-segmented name="sort" :current="$sort" default="pages" :options="$sorts" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-[13px] tabular-nums">
                <thead class="border-b border-rule text-left text-ink-2">
                    <tr>
                        <th class="py-2 pr-3 font-semibold">{{ __('User (uid)') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ __('First seen') }}</th>
                        <th class="py-2 pr-3 font-semibold">{{ __('First came from') }}</th>
                        <th class="py-2 pr-3 text-right font-semibold">{{ __('Days seen') }}</th>
                        <th class="py-2 pr-3 text-right font-semibold">{{ __('Pages read') }}</th>
                        <th class="py-2 pr-3 text-right font-semibold">{{ __('Last seen') }}</th>
                        <th class="py-2 text-right font-semibold">{{ __('Paid (all time)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-grid">
                    @forelse ($users as $user)
                        <tr>
                            <td class="py-1.5 pr-3">
                                <a href="{{ route('teams.sites.sales.users.show', [$team, $site, $user->uid]) }}" class="font-mono text-xs font-semibold text-ink underline decoration-rule hover:decoration-ink">{{ $user->uid }}</a>
                            </td>
                            <td class="py-1.5 pr-3 whitespace-nowrap">{{ $user->first_seen_at ? \Carbon\CarbonImmutable::parse($user->first_seen_at)->setTimezone($site->timezone)->translatedFormat('j M Y') : '—' }}</td>
                            <td class="py-1.5 pr-3">
                                @if ($user->first_source)
                                    <span class="flex items-center gap-1.5">
                                        <x-icon :name="Labels::sourceIcon($user->first_source)" class="size-3.5 text-ink-2" />
                                        {{ $sourceLabel($user->first_source) }}
                                        @if ($user->first_landing)
                                            <span class="truncate font-mono text-xs text-ink-3">{{ $user->first_landing }}</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-ink-3">{{ __('Not recorded') }}</span>
                                @endif
                            </td>
                            <td class="py-1.5 pr-3 text-right">{{ number_format($user->days) }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ number_format($user->pages) }}</td>
                            <td class="py-1.5 pr-3 text-right whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($user->last_day)->translatedFormat('j M') }}</td>
                            <td class="py-1.5 text-right whitespace-nowrap">
                                {{ collect($revenue[$user->uid] ?? [])->map(fn ($amount, $currency) => Money::format($amount, $currency))->implode(' · ') ?: '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-ink-3">{{ $search !== '' ? __('No user matches.') : __('No signed-in user in this period. Your app names its users in the log with uid=: see Site settings, Signed-in users.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $users->links() }}</div>
    </section>

    <p class="text-xs text-ink-3">{{ __('A uid is the opaque id your app writes in the log, never a name or an email. Users not seen for :days days are forgotten; the days\' counts stay.', ['days' => config('analytics.users_kept_days')]) }}</p>
</x-section-frame>
