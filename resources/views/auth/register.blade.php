<x-layouts.guest :title="__('Create an account')" :lede="$open ? __('You get a team of your own, and can invite people to it.') : __('Sign-up is by invitation only.')">
    @unless ($open)
        <p class="text-ink-2">{{ __('Ask someone on a team to invite you: the invitation\'s link lets you create your account with the email address it was sent to.') }}</p>
    @else
    <form method="POST" action="{{ route('register') }}" class="grid gap-3">
        @csrf
        <x-input :label="__('Name')" name="name" required autofocus autocomplete="name" />
        <x-input :label="__('Email')" name="email" type="email" :value="$email" required autocomplete="username" />
        <x-input :label="__('Password')" name="password" type="password" required autocomplete="new-password" :hint="__('At least 12 characters.')" />
        <x-input :label="__('Confirm password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-input :label="__('Team name (optional)')" name="team" :hint="__('Leave empty to name it after you.')" />

        <x-button class="mt-1 w-full">{{ __('Create account') }}</x-button>
    </form>
    @endunless

    <p class="mt-4 text-[13px] text-ink-2">
        {{ __('Already have an account?') }} <a href="{{ route('login') }}" class="hover:text-ink">{{ __('Sign in') }}</a>
    </p>
</x-layouts.guest>
