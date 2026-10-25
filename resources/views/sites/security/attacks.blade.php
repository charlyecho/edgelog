@php
    use App\Analytics\Security\SecurityNames;
    use App\Enums\CountKind;

    $injections = $period->counts(CountKind::SecurityInjection);
    arsort($injections);
    $injectionLabels = SecurityNames::injectionLabels();
    $postsSized = $security->figure('posts_sized');
    $largePosts = $security->figure('large_posts');
    $when = fn ($time) => $time->setTimezone($site->timezone)->translatedFormat('j M H:i');
    $answered = fn (array $answered) => collect($answered)->sortKeys()->map(fn ($count, $class) => "{$class} {$count}")->implode(', ');
    $mostAsked = fn (array $paths) => collect($paths)->sortDesc()->keys()->first() ?? '';
    $figureWords = ['gateway' => __('gateway errors (502, 503, 504)'), 'php' => __('.php requests')];
    $episodeRows = fn ($episodes) => $episodes->map(fn ($episode) => [
        $when($episode->started_at),
        $when($episode->ended_at),
        ['label' => $episode->address, 'mono' => true, 'href' => route('teams.sites.security.ip', [$team, $site, 'ip' => $episode->address, 'days' => $choice]), 'drawer' => true],
        number_format($episode->requests),
        ['label' => $mostAsked($episode->paths), 'mono' => true],
        $answered($episode->answered),
    ])->all();
@endphp

<x-section-frame section="security" :team="$team" :site="$site" current="attacks" :title="__('Attack attempts')" :period="$period" :choice="$choice"
    :lede="__('What did they try beyond looking around: tricks in the address, hammering a login form, or flooding the server?')">
    <x-security-partial :security="$security" />

    <x-stat-row :stats="[
        [__('Injection attempts'), $security->figure('injection'), __('Probes with an attack in the address: ../, ${jndi:, <script, union select...')],
        [__('Login and form posts'), $security->figure('login_posts'), __(':posts POSTs or more to a login or form path from one address within :seconds seconds.', ['posts' => config('analytics.security.login_posts'), 'seconds' => config('analytics.security.login_seconds')])],
        [__('Burst requests'), $security->figure('burst_requests'), __(':requests requests or more from one address within :seconds seconds, people and bots only.', ['requests' => config('analytics.security.burst_requests'), 'seconds' => config('analytics.security.burst_seconds')])],
    ]" />

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="text-[14px] font-bold">{{ __('Log signs worth a look') }}</h3>
        <ul class="mt-2 grid gap-1 text-[13px] text-ink-2">
            @forelse ($signs as $sign)
                <li class="text-bad">{{ __(':day: :value :figure, against a median of :median the 14 days before.', ['day' => \Carbon\CarbonImmutable::parse($sign['day'])->translatedFormat('D j M'), 'value' => number_format($sign['value']), 'figure' => $figureWords[$sign['figure']], 'median' => number_format($sign['median'])]) }}</li>
            @empty
                <li>{{ __('Nothing stands out: gateway errors and .php requests stayed near the usual.') }}</li>
            @endforelse
            <li>
                @if ($postsSized === 0)
                    {{ __('No POST had its size in the log (request_length=), so large ones cannot be told.') }}
                @elseif ($largePosts === 0)
                    {{ __('No POST of :bytes bytes or more (:count POSTs with a recorded size).', ['bytes' => number_format(config('analytics.security.large_post_bytes')), 'count' => number_format($postsSized)]) }}
                @else
                    <span class="text-bad">{{ __(':large POSTs of :bytes bytes or more, of :count with a recorded size.', ['large' => number_format($largePosts), 'bytes' => number_format(config('analytics.security.large_post_bytes')), 'count' => number_format($postsSized)]) }}</span>
                @endif
            </li>
        </ul>
        <p class="mt-2 text-xs text-ink-3">{{ __('Worth a look, never proof of an attack: a day at 3 times the median of the 14 days before it (with 7 of them recorded) and at least 20.') }}</p>
    </section>

    <x-top-list :title="__('Path tricks and injection, by kind')" :unit="__('Probes with an attack in the address')" :rows="collect($injections)->map(fn ($count, $kind) => ['label' => $injectionLabels[$kind] ?? $kind, 'count' => $count])->values()->all()"
        :total="$security->figure('injection')" tint="var(--bad)" :shown="6" />

    <x-rows-table :title="__('Login and form hammering')" :unit="__('Addresses are kept :days days.', ['days' => config('analytics.security.kept_days')])"
        :columns="[[__('From'), 'left'], [__('To'), 'left'], [__('Address'), 'left'], [__('POSTs'), 'right'], [__('Path'), 'left'], [__('Answered'), 'left']]"
        :rows="$episodeRows($logins)" :shown="15" :empty="__('No login hammering in this period.')" />

    <x-rows-table :title="__('Bursts')" :unit="__('Addresses are kept :days days.', ['days' => config('analytics.security.kept_days')])"
        :columns="[[__('From'), 'left'], [__('To'), 'left'], [__('Address'), 'left'], [__('Requests'), 'right'], [__('Most asked'), 'left'], [__('Answered'), 'left']]"
        :rows="$episodeRows($bursts)" :shown="15" :empty="__('No burst in this period.')" />
</x-section-frame>
