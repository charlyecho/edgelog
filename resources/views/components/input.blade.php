@props(['label', 'name', 'type' => 'text', 'value' => null, 'bag' => 'default', 'hint' => null])

@php($hasError = $errors->getBag($bag)->has($name))

<label class="grid gap-1.5 text-[13px] font-medium text-ink-2">
    {{ $label }}
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class([
            'h-10 w-full min-w-0 rounded-[9px] border bg-surface px-3 text-[15px] font-normal text-ink placeholder:text-ink-3 hover:border-ink-3',
            'border-bad' => $hasError,
            'border-rule' => ! $hasError,
        ]) }}
    >
    @if ($hint)
        <span class="text-xs font-normal text-ink-3">{{ $hint }}</span>
    @endif
    @error($name, $bag)
        <span class="text-[13px] font-normal text-bad">{{ $message }}</span>
    @enderror
</label>
