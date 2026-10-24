@props(['period'])

@php
    $total = $period->requests();
    $groups = collect(\App\Enums\TrafficGroup::cases())->map(fn ($group) => ['group' => $group, 'requests' => $period->group($group)->requests]);
@endphp

<div>
    <div class="flex h-3 w-full gap-[2px] overflow-hidden rounded-full bg-sunk" role="img" aria-label="{{ __('Every request, in five groups') }}">
        @foreach ($groups as $row)
            @if ($row['requests'] > 0)
                <span class="h-full first:rounded-l-full last:rounded-r-full" style="width: {{ $row['requests'] / max(1, $total) * 100 }}%; background: var(--group-{{ $row['group']->value }})" title="{{ $row['group']->label() }}: {{ number_format($row['requests']) }}"></span>
            @endif
        @endforeach
    </div>

    <table class="mt-3 w-full text-left tabular-nums">
        <tbody class="divide-y divide-grid">
            @foreach ($groups as $row)
                <tr>
                    <td class="py-1.5 pr-3">
                        <span class="flex items-center gap-2 font-semibold">
                            <span class="size-2.5 flex-none rounded-full" style="background: var(--group-{{ $row['group']->value }})"></span>
                            {{ $row['group']->label() }}
                        </span>
                        <span class="block pl-[18px] text-xs text-ink-3">{{ $row['group']->description() }}</span>
                    </td>
                    <td class="py-1.5 text-right font-semibold">{{ number_format($row['requests']) }}</td>
                    <td class="w-12 py-1.5 text-right text-xs text-ink-3">{{ $total > 0 ? round($row['requests'] / $total * 100).'%' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t border-rule">
                <td class="py-1.5 font-semibold">{{ __('Every request') }}</td>
                <td class="py-1.5 text-right font-bold">{{ number_format($total) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
