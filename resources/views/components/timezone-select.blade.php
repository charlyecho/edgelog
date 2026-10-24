@props(['value' => 'UTC'])

<x-select :label="__('Time zone')" name="timezone" {{ $attributes }}>
    @foreach (DateTimeZone::listIdentifiers() as $zone)
        <option value="{{ $zone }}" @selected(old('timezone', $value) === $zone)>{{ str_replace('_', ' ', $zone) }}</option>
    @endforeach
</x-select>
