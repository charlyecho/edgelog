<x-layouts.guest :title="__('Confirm your password')" :lede="__('This is a protected area. Please confirm your password to continue.')">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="grid gap-3">
        @csrf
        <x-input :label="__('Password')" name="password" type="password" required autofocus autocomplete="current-password" />
        <x-button class="mt-1 w-full">{{ __('Confirm') }}</x-button>
    </form>
</x-layouts.guest>
