@props(['stats'])

{{-- A row of figures: each stat is [label, value, info?, tone?]. --}}
<div {{ $attributes->class('overflow-hidden rounded-[10px] border border-rule bg-rule shadow-card') }}>
    <div @class(['grid grid-cols-2 gap-px', 'lg:grid-cols-3' => count($stats) === 3, 'lg:grid-cols-4' => count($stats) === 4, 'lg:grid-cols-5' => count($stats) === 5, 'lg:grid-cols-6' => count($stats) >= 6])>
        @foreach ($stats as $stat)
            <div class="bg-surface p-4">
                <p class="flex items-center gap-1 text-[13px] font-semibold text-ink-2">
                    {{ $stat[0] }}
                    @if (! empty($stat[2]))
                        <x-info :text="$stat[2]" />
                    @endif
                </p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-bad' => ($stat[3] ?? null) === 'bad', 'text-good' => ($stat[3] ?? null) === 'good'])>{{ is_int($stat[1]) ? number_format($stat[1]) : $stat[1] }}</p>
            </div>
        @endforeach
    </div>
</div>
