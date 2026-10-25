@props(['security'])

@unless ($security->isWhole())
    <x-notice tone="warn">{{ __('Some days of this period were counted before Security was kept: count them again to see them here. The figures below leave them out.') }}</x-notice>
@endunless
