@if (is_file((string) config('analytics.geoip.city')) || is_file((string) config('analytics.geoip.country')))
    <p {{ $attributes->class('text-xs text-ink-3') }}>{{ __('Countries and cities without a log field of their own:') }} <a href="https://db-ip.com" class="underline hover:text-ink" rel="noopener">{{ __('IP geolocation by DB-IP') }}</a>.</p>
@endif
