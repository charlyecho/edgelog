@php
    use App\Enums\ErrorStatus;
    use App\Models\SiteErrorOccurrence;

    $local = fn (\Carbon\CarbonInterface $time) => $time->setTimezone($site->timezone);
    $frames = collect(explode("\n", (string) $error->last_stack))->filter(fn (string $frame) => trim($frame) !== '');
    $isVendor = fn (string $frame) => preg_match('~(?:/vendor/|VENDORPATH/|SYSTEMPATH/|\[internal function\]|\{main\})~', $frame) === 1;
@endphp

<x-section-frame section="site" :team="$team" :site="$site" current="errors" :title="$error->title()">
    <p class="-mt-2 text-[13px]"><a href="{{ route('teams.sites.site.errors', [$team, $site]) }}" class="text-ink-2 underline hover:text-ink">← {{ __('Errors') }}</a></p>

    <section class="grid gap-3 rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <p class="text-[15px] break-words whitespace-pre-line text-ink">{{ $error->message }}</p>
        <dl class="grid gap-1 text-[13px] sm:grid-cols-[140px_1fr]">
            <dt class="text-ink-2">{{ __('Thrown in') }}</dt>
            <dd class="font-mono text-xs break-all">{{ $error->place() ?? '—' }}</dd>
            <dt class="text-ink-2">{{ __('Log') }}</dt>
            <dd>{{ $error->kind->label() }} · {{ $error->level }}</dd>
            <dt class="text-ink-2">{{ __('Status') }}</dt>
            <dd>
                {{ $error->status->label() }}
                @if ($error->status === ErrorStatus::Resolved && $error->resolved_at)
                    <span class="text-ink-3">{{ __('since :when', ['when' => $local($error->resolved_at)->translatedFormat('j M Y H:i')]) }}</span>
                @elseif ($error->status === ErrorStatus::Open && $error->regressed_at)
                    <span class="font-semibold text-bad">{{ __('back again :when, after it was resolved', ['when' => $local($error->regressed_at)->translatedFormat('j M Y H:i')]) }}</span>
                @endif
            </dd>
        </dl>

        @if ($canChange)
            <div class="flex flex-wrap gap-2">
                @foreach ($error->status === ErrorStatus::Open ? [ErrorStatus::Resolved, ErrorStatus::Ignored] : [ErrorStatus::Open] as $next)
                    <form method="POST" action="{{ route('teams.sites.site.errors.update', [$team, $site, $error]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="status" value="{{ $next->value }}">
                        <x-button :variant="$next === ErrorStatus::Resolved ? 'primary' : 'secondary'" size="sm">{{ match ($next) {
                            ErrorStatus::Resolved => __('Resolve'),
                            ErrorStatus::Ignored => __('Ignore'),
                            ErrorStatus::Open => __('Open again'),
                        } }}</x-button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>

    <x-stat-row :stats="[
        [__('In all'), $error->times_seen, __('Every time it was logged since it was first seen.')],
        [__('Last :count days', ['count' => count($daily)]), $total],
        [__('First seen'), $local($error->first_seen_at)->translatedFormat('j M Y H:i')],
        [__('Last seen'), $local($error->last_seen_at)->translatedFormat('j M Y H:i')],
    ]" />

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="text-[14px] font-bold">{{ __('Each day') }}</h3>
        <p class="text-xs text-ink-3">{{ __('Times it was logged each day of the last :count, in :timezone.', ['count' => count($daily), 'timezone' => $site->timezone]) }}</p>
        <x-sparkline :values="array_values($daily)" color="var(--bad)" :width="640" class="mt-3 h-auto max-w-none" />
    </section>

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <x-top-list :title="__('Pages')" :unit="__('The requests it answered, when they are known')" :rows="$pages" :total="$total" tint="var(--bad)" :shown="8" />
        <x-top-list :title="__('Referrers')" :unit="__('Where those requests came from')" :rows="$referrers" :total="$total" tint="var(--bad)" :shown="8" />
    </div>

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="mb-2 text-[14px] font-bold">{{ __('Stack trace') }}</h3>
        @if ($frames->isEmpty())
            <p class="text-ink-3">{{ __('The log wrote no stack trace for it.') }}</p>
        @else
            <ol class="overflow-x-auto rounded-[9px] border border-rule bg-page px-3 py-2.5 font-mono text-[12px] leading-relaxed">
                @foreach ($frames as $frame)
                    <li @class(['whitespace-pre', 'text-ink-3' => $isVendor($frame), 'font-semibold text-ink' => ! $isVendor($frame)])>{{ $frame }}</li>
                @endforeach
            </ol>
            <p class="mt-2 text-xs text-ink-3">{{ __('The last time it was logged. Your own code in bold, the framework and vendor/ in grey.') }}</p>
        @endif
    </section>

    <x-rows-table :title="__('Latest times')" :unit="__('The last :count, with the request each answered. From the error log itself, or found in the access log at the same second.', ['count' => $latest->count()])"
        :columns="[[__('When'), 'left'], [__('Request'), 'left'], [__('Answered'), 'right'], [__('Referrer'), 'left'], [__('Address'), 'left'], [__('Found in'), 'left']]"
        :rows="$latest->map(fn (SiteErrorOccurrence $occurrence) => [
            $local($occurrence->at)->translatedFormat('j M H:i:s'),
            $occurrence->path === null ? ['label' => $occurrence->looked_up_at === null ? __('looking…') : __('unknown'), 'tone' => 'muted'] : ['label' => trim($occurrence->method.' '.$occurrence->path), 'mono' => true],
            $occurrence->status === null ? '—' : (string) $occurrence->status,
            $occurrence->referrer ?? '—',
            $occurrence->address === null ? '—' : ['label' => $occurrence->address, 'mono' => true],
            match ($occurrence->request_from) {
                SiteErrorOccurrence::FROM_LOG => __('error log'),
                SiteErrorOccurrence::FROM_ACCESS => __('access log'),
                default => '—',
            },
        ])->all()" :shown="20" :empty="__('No occurrence in the last :count days.', ['count' => config('ingest.error_occurrence_days')])" />
</x-section-frame>
