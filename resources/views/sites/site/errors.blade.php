@php
    use App\Enums\ErrorStatus;
    use App\Ingest\ShipperSetup;

    $local = fn (\Carbon\CarbonInterface $time) => $time->setTimezone($site->timezone);
    $statuses = collect(ErrorStatus::cases())->mapWithKeys(fn (ErrorStatus $case) => [$case->value => $case->label().' · '.number_format($counts[$case->value] ?? 0)])->all();
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="errors" :title="__('Errors')"
    :lede="__('The errors and exceptions your application logs, as edgelog-ship --errors sends them: grouped by what was thrown and where, with the page and the referrer of each time, read from the error log or found in the access log at the same second.')">
    @if (! $shipping && $errors->isEmpty())
        <x-card :title="__('No error log is sent yet')" :description="__('Run one more shipper per error log, next to the one that sends the access log. Warnings, notices and deprecations are left out: only errors and exceptions are kept.')">
            @include('sites.site.errors-setup')
        </x-card>
    @else
        <x-stat-row :stats="[
            [__('Open errors'), (int) ($counts[ErrorStatus::Open->value] ?? 0), __('Errors nobody resolved or ignored yet.')],
            [__('Last 24 hours'), $lastDay, __('Times an error was logged, every status.'), $lastDay > 0 ? 'bad' : null],
            [__('New in 24 hours'), $newLastDay, __('Errors seen for the first time.')],
        ]" />

        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-segmented name="status" :current="$status->value" :default="ErrorStatus::Open->value" :options="$statuses" :aria-label="__('Status')" />
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-[13px] tabular-nums">
                    <thead class="border-b border-rule text-left text-ink-2">
                        <tr>
                            <th class="py-2 pr-3 font-semibold">{{ __('Error') }}</th>
                            <th class="py-2 pr-3 font-semibold">{{ __('Thrown in') }}</th>
                            <th class="py-2 pr-3 text-right font-semibold">{{ __('24 h') }}</th>
                            <th class="py-2 pr-3 text-right font-semibold">{{ __(':count days', ['count' => config('ingest.error_occurrence_days')]) }}</th>
                            <th class="py-2 pr-3 text-right font-semibold">{{ __('In all') }}</th>
                            <th class="py-2 pr-3 text-right font-semibold">{{ __('Last seen') }}</th>
                            <th class="py-2 text-right font-semibold">{{ __('First seen') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-grid">
                        @forelse ($errors as $error)
                            <tr class="align-top">
                                <td class="max-w-[520px] py-2 pr-3">
                                    <a href="{{ route('teams.sites.site.errors.show', [$team, $site, $error]) }}" class="font-semibold text-ink underline decoration-rule underline-offset-2 hover:decoration-ink">{{ $error->title() }}</a>
                                    @if ($error->regressed_at !== null && $error->status === ErrorStatus::Open)
                                        <span class="ml-1 rounded-sm bg-[color-mix(in_srgb,var(--bad)_14%,transparent)] px-1.5 py-0.5 text-[11px] font-semibold text-bad">{{ __('Back again') }}</span>
                                    @endif
                                    <p class="line-clamp-2 break-words text-ink-2" title="{{ $error->message }}">{{ $error->message }}</p>
                                    <p class="text-xs text-ink-3">{{ $error->kind->label() }} · {{ $error->level }}</p>
                                </td>
                                <td class="max-w-[280px] py-2 pr-3"><span class="block truncate font-mono text-xs text-ink-2" title="{{ $error->place() }}">{{ $error->place() ?? '—' }}</span></td>
                                <td class="py-2 pr-3 text-right">{{ number_format((int) ($recent[$error->id]->day ?? 0)) }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format((int) ($recent[$error->id]->month ?? 0)) }}</td>
                                <td class="py-2 pr-3 text-right">{{ number_format($error->times_seen) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap" title="{{ $local($error->last_seen_at)->translatedFormat('j M Y H:i:s') }}">{{ $error->last_seen_at->diffForHumans(short: true) }}</td>
                                <td class="py-2 text-right whitespace-nowrap">{{ $local($error->first_seen_at)->translatedFormat('j M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-4 text-ink-3">{{ match ($status) {
                                ErrorStatus::Open => __('No open error. Every error logged is resolved or ignored.'),
                                ErrorStatus::Resolved => __('No resolved error.'),
                                ErrorStatus::Ignored => __('No ignored error.'),
                            } }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $errors->links() }}</div>
        </section>

        <details class="rounded-[10px] border border-rule bg-surface shadow-card">
            <summary class="cursor-pointer px-4 py-3 font-bold">{{ __('Send another error log') }}</summary>
            <div class="border-t border-rule p-4">
                @include('sites.site.errors-setup')
            </div>
        </details>

        <p class="text-xs text-ink-3">{{ __('Each occurrence is kept :days days, with the request it answered; an error stays, with its count, until the site is deleted. A resolved error that comes back opens again.', ['days' => config('ingest.error_occurrence_days')]) }}</p>
    @endif
</x-section-frame>
