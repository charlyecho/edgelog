<x-layouts.guest :title="__('Forgot your password?')" :lede="__('Enter your email and we will send you a link to choose a new password.')">
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-3">
        @csrf
        <x-input :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
        <x-button class="mt-1 w-full">{{ __('Send the link') }}</x-button>
    </form>

    <p class="mt-4 text-[13px] text-ink-2"><a href="{{ route('login') }}" class="hover:text-ink">{{ __('Back to sign in') }}</a></p>
</x-layouts.guest>
