@php
    use App\Analytics\Labels;
    use App\Analytics\Security\SecurityNames;

    $labels = SecurityNames::categoryLabels();
    $signWords = fn (string $sign) => match ($sign) {
        'bare_ip' => __('Sent to the bare IP'),
        'forged_referrer' => __('Forged referrer'),
        default => __(ucfirst($sign)),
    };
    $when = fn ($time) => $time->setTimezone($site->timezone)->translatedFormat('j M H:i:s');
    $classes = fn (array $answered) => collect($answered)->sortKeys()->map(fn ($count, $class) => "{$class} {$count}")->implode(', ');
@endphp

<div class="grid gap-4">
    @if ($report === null)
        <p class="text-ink-2">{{ $address === '' ? __('Type an IP address.') : __(':address is not an IP address.', ['address' => $address]) }}</p>
    @else
        <header>
            <p class="font-mono text-lg font-bold">{{ $address }}</p>
            <p class="text-[13px] text-ink-2">
                @if ($report->country() !== '')
                    <span aria-hidden="true">{{ Labels::flag($report->country()) }}</span> {{ \Locale::getDisplayRegion('-'.$report->country(), app()->getLocale()) }} ·
                @endif
                {{ __('network :network', ['network' => SecurityNames::network($address)]) }}
                @foreach ($report->signs() as $sign)
                    · <span class="text-bad">{{ $signWords($sign) }}</span>
                @endforeach
            </p>
        </header>

        @if ($report->rows->isEmpty() && $report->logged === 0)
            <p class="text-ink-2">{{ __('Nothing kept about this address in the period: it was no suspect, and the log holds no request of it.') }}</p>
        @else
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-sunk p-3 text-[13px] sm:grid-cols-3">
                @foreach ([
                    [__('Requests'), number_format(max($report->figure('requests'), $report->logged))],
                    [__('Probes'), number_format($report->figure('probes'))],
                    [__('Attack attempts'), number_format($report->figure('attempts'))],
                    [__('Fake-browser requests'), number_format($report->figure('fake_requests'))],
                    [__('Days seen'), number_format($report->daysSeen())],
                    [__('First seen'), $report->firstSeen() ? $when($report->firstSeen()) : '—'],
                ] as [$label, $value])
                    <div><dt class="text-xs text-ink-3">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ $value }}</dd></div>
                @endforeach
            </dl>

            @if ($report->sum('categories') !== [])
                <section>
                    <h3 class="mb-1 text-[13px] font-bold">{{ __('What it looked for') }}</h3>
                    <ul class="grid gap-0.5 text-[13px]">
                        @foreach ($report->sum('categories') as $category => $count)
                            <li class="flex justify-between gap-3"><span>{{ $labels[$category] ?? $category }}</span><span class="tabular-nums">{{ number_format($count) }}</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-ink-3">{{ __('Answered: :classes', ['classes' => $classes($report->sum('answered')) ?: '—']) }}</p>
                </section>
            @endif

            <section>
                <h3 class="mb-1 text-[13px] font-bold">{{ $report->paths !== [] ? __('Paths it asked for, from the log') : __('Paths it asked for (each day\'s top :count, summed)', ['count' => SecurityNames::ROW_PATHS]) }}</h3>
                <ul class="grid gap-0.5 text-xs">
                    @if ($report->paths !== [])
                        @foreach (array_slice($report->paths, 0, 50, true) as $path => [$count, $answered])
                            <li class="flex justify-between gap-3"><span class="truncate font-mono" title="{{ $path }}">{{ $path }}</span><span class="flex-none tabular-nums text-ink-2">{{ number_format($count) }} · {{ $classes($answered) }}</span></li>
                        @endforeach
                    @else
                        @foreach (array_slice($report->sum('paths'), 0, 50, true) as $path => $count)
                            <li class="flex justify-between gap-3"><span class="truncate font-mono" title="{{ $path }}">{{ $path }}</span><span class="tabular-nums text-ink-2">{{ number_format($count) }}</span></li>
                        @endforeach
                    @endif
                </ul>
                @if (count($report->paths) > 50)
                    <p class="mt-1 text-xs text-ink-3">{{ __('and :count other addresses', ['count' => number_format(count($report->paths) - 50)]) }}</p>
                @endif
            </section>

            @if ($report->userAgents() !== [])
                <section>
                    <h3 class="mb-1 text-[13px] font-bold">{{ __('User agents') }}</h3>
                    <ul class="grid gap-1 text-xs">
                        @foreach (array_slice($report->userAgents(), 0, 10, true) as $agent => $count)
                            <li class="font-mono break-all text-ink-2">{{ $agent === '' ? __('(none)') : $agent }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($report->attacks->isNotEmpty())
                <section>
                    <h3 class="mb-1 text-[13px] font-bold">{{ __('Login hammering and bursts') }}</h3>
                    <ul class="grid gap-0.5 text-[13px]">
                        @foreach ($report->attacks as $attack)
                            <li>{{ $attack->kind === 'login' ? __('Login hammering') : __('Burst') }}: {{ $when($attack->started_at) }} → {{ $attack->ended_at->setTimezone($site->timezone)->format('H:i:s') }}, {{ trans_choice(':count request|:count requests', $attack->requests) }} · {{ $classes($attack->answered) }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section>
                <h3 class="mb-1 text-[13px] font-bold">{{ __('Every request, from the log') }}</h3>
                @if ($report->logged === 0)
                    <p class="text-[13px] text-ink-3">{{ __('The log no longer holds its requests: the shipped files are kept :days days.', ['days' => config('ingest.keep')]) }}</p>
                @else
                    <p class="mb-1 text-xs text-ink-3">
                        {{ trans_choice(':count request in the log since the period began, today\'s included.|:count requests in the log since the period began, today\'s included.', $report->logged, ['count' => number_format($report->logged)]) }}
                        @if ($report->logged > count($report->requests))
                            {{ __('The newest :count are listed.', ['count' => number_format(count($report->requests))]) }}
                        @endif
                    </p>
                    <div class="max-h-[28rem] overflow-auto rounded-lg border border-rule">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-surface text-left text-ink-2">
                                <tr><th class="px-2 py-1.5">{{ __('When') }}</th><th class="px-2 py-1.5">{{ __('Request') }}</th><th class="px-2 py-1.5 text-right">{{ __('Answer') }}</th><th class="px-2 py-1.5 text-right">{{ __('Size') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-grid">
                                @foreach ($report->requests as $hit)
                                    <tr class="align-top" title="{{ __('Referrer: :referrer', ['referrer' => $hit->referrer ?: '—']) }}&#10;{{ $hit->userAgent }}">
                                        <td class="px-2 py-1 whitespace-nowrap tabular-nums text-ink-2">{{ $when($hit->when) }}</td>
                                        <td class="px-2 py-1 font-mono break-all">{{ $hit->method }} {{ $hit->url }}</td>
                                        <td @class(['px-2 py-1 text-right tabular-nums', 'text-good' => $hit->status < 300, 'text-bad' => $hit->status >= 500])>{{ $hit->status }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums text-ink-2">{{ $hit->size === null ? '—' : number_format($hit->size) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section>
                <h3 class="mb-1 text-[13px] font-bold">{{ __('To block it') }}</h3>
                <code class="block rounded-md bg-sunk px-2 py-1.5 font-mono text-xs">deny {{ $address }};</code>
                <p class="mt-1 text-xs text-ink-3">{{ __('Review before blocking: a shared address (an office, a mobile network, a VPN) can carry real visitors too.') }}</p>
            </section>
        @endif
    @endif
</div>
