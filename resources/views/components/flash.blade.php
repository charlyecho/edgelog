@php
    $status = match (session('status')) {
        'profile-information-updated' => __('Profile saved.'),
        'password-updated' => __('Password changed.'),
        'two-factor-authentication-enabled' => __('Scan the QR code, then enter a code from the app to finish.'),
        'two-factor-authentication-confirmed' => __('Two-step sign-in is on.'),
        'two-factor-authentication-disabled' => __('Two-step sign-in is off.'),
        'recovery-codes-generated' => __('New recovery codes generated. The old ones no longer work.'),
        default => session('status'),
    };
@endphp

@if ($status)
    <x-notice class="mb-6">{{ $status }}</x-notice>
@endif
