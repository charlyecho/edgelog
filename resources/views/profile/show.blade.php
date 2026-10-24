<x-layouts.app :title="__('Your account')" :lede="__('Your name, email, password and two-step sign-in.')">
    <div class="grid max-w-2xl gap-8">
        <x-card :title="__('Profile')">
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="grid gap-3">
                @csrf
                @method('PUT')
                <x-input :label="__('Name')" name="name" :value="$user->name" bag="updateProfileInformation" required autocomplete="name" />
                <x-input :label="__('Email')" name="email" type="email" :value="$user->email" bag="updateProfileInformation" required autocomplete="username" />
                <div><x-button variant="secondary">{{ __('Save') }}</x-button></div>
            </form>
        </x-card>

        <x-card :title="__('Password')" :description="__('At least 12 characters.')">
            <form method="POST" action="{{ route('user-password.update') }}" class="grid gap-3">
                @csrf
                @method('PUT')
                <x-input :label="__('Current password')" name="current_password" type="password" bag="updatePassword" required autocomplete="current-password" />
                <x-input :label="__('New password')" name="password" type="password" bag="updatePassword" required autocomplete="new-password" />
                <x-input :label="__('Confirm new password')" name="password_confirmation" type="password" bag="updatePassword" required autocomplete="new-password" />
                <div><x-button variant="secondary">{{ __('Change password') }}</x-button></div>
            </form>
        </x-card>

        <x-card :title="__('Two-step sign-in')" :description="__('A 6-digit code from an authenticator app, asked after your password.')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-ink-2">
                    {{ $user->hasEnabledTwoFactorAuthentication() ? __('Two-step sign-in is on.') : __('Two-step sign-in is off.') }}
                </p>
                <x-button variant="secondary" :href="route('profile.two-factor')">{{ __('Manage two-step sign-in') }}</x-button>
            </div>
        </x-card>
    </div>
</x-layouts.app>
