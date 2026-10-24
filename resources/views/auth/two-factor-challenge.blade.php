<x-layouts.guest :title="__('Two-step sign-in')">
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="grid gap-3">
        @csrf
        <x-input :label="__('Code')" name="code" inputmode="numeric" autofocus autocomplete="one-time-code" class="font-mono tracking-[.3em]" :hint="__('The 6 digits your authenticator app shows now.')" />
        <x-input :label="__('Or a recovery code')" name="recovery_code" autocomplete="off" class="font-mono" />
        <x-button class="mt-1 w-full">{{ __('Sign in') }}</x-button>
    </form>
</x-layouts.guest>
