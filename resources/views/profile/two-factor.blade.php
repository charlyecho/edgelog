<x-layouts.app :title="__('Two-step sign-in')">
    <x-slot:trail>
        <x-crumb :href="route('profile.show')">{{ __('Your account') }}</x-crumb>
    </x-slot:trail>

    <x-card class="max-w-2xl">
        @if (! $user->two_factor_secret)
            <p class="mb-4 text-ink-2">{{ __('Two-step sign-in is off. Turn it on to be asked for a code from your authenticator app after your password.') }}</p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <x-button>{{ __('Turn on') }}</x-button>
            </form>
        @elseif (! $user->two_factor_confirmed_at)
            <p class="mb-4 text-ink-2">{{ __('Scan this QR code with your authenticator app, then enter the code it shows.') }}</p>
            <div class="mb-3 inline-block rounded-[10px] border border-rule bg-white p-3">{!! $user->twoFactorQrCodeSvg() !!}</div>
            <p class="mb-4 text-[13px] text-ink-3">{{ __('Setup key:') }} <code class="font-mono text-ink">{{ decrypt($user->two_factor_secret) }}</code></p>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="w-48">
                    <x-input :label="__('Code')" name="code" bag="confirmTwoFactorAuthentication" inputmode="numeric" autocomplete="one-time-code" class="font-mono tracking-[.3em]" required autofocus />
                </div>
                <x-button>{{ __('Confirm') }}</x-button>
            </form>

            <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-3">
                @csrf
                @method('DELETE')
                <x-button variant="secondary" size="sm">{{ __('Cancel') }}</x-button>
            </form>
        @else
            <p class="mb-4 text-ink-2">{{ __('Two-step sign-in is on.') }}</p>

            @if (in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated']))
                <p class="font-semibold">{{ __('Recovery codes') }}</p>
                <p class="mb-2 text-ink-2">{{ __('Keep them somewhere safe. Each one works once, if you lose your phone.') }}</p>
                <ul class="mb-4 grid grid-cols-2 gap-1 rounded-[10px] bg-sunk p-4 font-mono text-[13px]">
                    @foreach ($user->recoveryCodes() as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="flex gap-2">
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                    @csrf
                    <x-button variant="secondary">{{ __('New recovery codes') }}</x-button>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">{{ __('Turn off') }}</x-button>
                </form>
            </div>
        @endif
    </x-card>
</x-layouts.app>
