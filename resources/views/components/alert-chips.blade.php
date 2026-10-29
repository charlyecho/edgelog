@props(['alerts'])

{{-- What is wrong, as small chips: each alert is {tone, label, detail}, the detail shown on hover. --}}
@if ($alerts !== [])
    <span {{ $attributes->class('flex flex-wrap gap-1.5') }}>
        @foreach ($alerts as $alert)
            <span title="{{ $alert['detail'] }}" @class([
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-semibold',
                'border-bad/40 bg-bad/10 text-bad' => $alert['tone'] === 'bad',
                'border-warn/40 bg-warn/10 text-warn' => $alert['tone'] === 'warn',
                'border-rule text-ink-3' => $alert['tone'] === 'muted',
            ])>
                <span @class(['size-1.5 rounded-full', 'bg-bad' => $alert['tone'] === 'bad', 'bg-warn' => $alert['tone'] === 'warn', 'bg-ink-3' => $alert['tone'] === 'muted'])></span>
                {{ $alert['label'] }}
            </span>
        @endforeach
    </span>
@endif
