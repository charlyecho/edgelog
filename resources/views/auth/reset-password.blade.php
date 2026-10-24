<x-layouts.guest :title="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="grid gap-3">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-input :label="__('Email')" name="email" type="email" :value="$request->email" required autocomplete="username" />
        <x-input :label="__('New password')" name="password" type="password" required autofocus autocomplete="new-password" :hint="__('At least 12 characters.')" />
        <x-input :label="__('Confirm password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-button class="mt-1 w-full">{{ __('Save the password') }}</x-button>
    </form>
</x-layouts.guest>
