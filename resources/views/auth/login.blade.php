<x-layouts.guest :title="__('Sign in')" :lede="__('To see your sites\' traffic.')">
    <form method="POST" action="{{ route('login') }}" class="grid gap-3">
        @csrf
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
        <x-input :label="__('Password')" name="password" type="password" required autocomplete="current-password" />

        <label class="flex items-center gap-2 text-ink">
            <input type="checkbox" name="remember" class="size-[18px] accent-[var(--ink)]"> {{ __('Keep me signed in') }}
        </label>

        <x-button class="mt-1 w-full">{{ __('Sign in') }}</x-button>
    </form>

    <p class="mt-4 flex justify-between text-[13px] text-ink-2">
        <a href="{{ route('password.request') }}" class="hover:text-ink">{{ __('Forgot password?') }}</a>
        @if (config('auth.registration_open'))
            <a href="{{ route('register') }}" class="hover:text-ink">{{ __('Create an account') }}</a>
        @endif
    </p>
</x-layouts.guest>
