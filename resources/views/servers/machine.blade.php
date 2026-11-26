@php
    use Illuminate\Support\Number;

    $latest = $range->latest();
    $disks = collect($latest->disks)->sortBy('mount')->values();
    $size = fn (int $bytes) => Number::fileSize($bytes, maxPrecision: 1);
@endphp

<x-card :title="__('The machine')" :description="__('As its last sample tells it: the processors, memory and swap the system sees, and each file system df reads.')">
    <dl class="grid gap-x-6 gap-y-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="font-semibold">{{ __('Processors') }}</dt>
            <dd class="text-ink-2">{{ trans_choice(':count core|:count cores', $latest->cores) }}</dd>
        </div>

        <div>
            <dt class="font-semibold">{{ __('Memory') }}</dt>
            <dd class="text-ink-2">{{ $size($latest->memory_total) }}</dd>
        </div>

        <div>
            <dt class="font-semibold">{{ __('Swap') }}</dt>
            <dd class="text-ink-2">{{ $latest->swap_total === 0 ? __('None') : $size($latest->swap_total) }}</dd>
        </div>

        <div>
            <dt class="font-semibold">{{ __('Disks') }}</dt>
            <dd class="text-ink-2">
                @forelse ($disks as $disk)
                    <span class="block"><span class="font-mono text-xs">{{ $disk['mount'] }}</span> · {{ $size($disk['size']) }}</span>
                @empty
                    {{ __('No disk was read.') }}
                @endforelse
            </dd>
        </div>
    </dl>
</x-card>
