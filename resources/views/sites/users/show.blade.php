@php
    use App\Analytics\Labels;
    use App\Enums\ConversionAction;
    use App\Enums\SourceType;
    use App\Integrations\Money;

    $first = $user?->first_seen_at?->toImmutable()->setTimezone($site->timezone);
@endphp

<x-section-frame section="sales" :team="$team" :site="$site" current="users" :title="$uid">
    <p class="-mt-2 text-[13px]"><a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('teams.sites.sales.users', [$team, $site]) }}" class="text-ink-2 underline hover:text-ink">← {{ __('Users') }}</a></p>

    <x-stat-row :stats="[
        [__('First seen'), $first?->translatedFormat('j M Y H:i') ?? '—'],
        [__('Last seen'), $days->first() ? \Carbon\CarbonImmutable::parse($days->first()->day)->translatedFormat('j M Y') : '—'],
        [__('Days seen'), $days->count(), __('The days kept with this user signed in.')],
        [__('Pages read'), (int) $days->sum('views')],
    ]" />

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="mb-2 text-[14px] font-bold">{{ __('Their first visit') }}</h3>
        @if ($user?->first_source)
            <dl class="grid gap-1 text-[13px] sm:grid-cols-[160px_1fr]">
                <dt class="text-ink-2">{{ __('Came from') }}</dt>
                <dd class="flex items-center gap-1.5"><x-icon :name="Labels::sourceIcon($user->first_source)" class="size-3.5 text-ink-2" />{{ SourceType::tryFrom($user->first_source)?->label() ?? $user->first_source }}</dd>
                <dt class="text-ink-2">{{ __('Referrer') }}</dt>
                <dd>{{ $user->first_referrer ?: '—' }}</dd>
                <dt class="text-ink-2">{{ __('Landed on') }}</dt>
                <dd class="font-mono text-xs">{{ $user->first_landing ?: '—' }}</dd>
            </dl>
            <p class="mt-2 text-xs text-ink-3">{{ __('The first visit kept in which they were signed in: what their payments are credited to.') }}</p>
        @else
            <p class="text-ink-3">{{ __('Not recorded: their first signed-in visit read no page.') }}</p>
        @endif
    </section>

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <x-rows-table :title="__('Days seen')" :unit="__('Each day they were signed in, with the pages they read.')"
            :columns="[[__('Day'), 'left'], [__('Pages read'), 'right']]"
            :rows="$days->map(fn ($day) => [\Carbon\CarbonImmutable::parse($day->day)->translatedFormat('D j M Y'), number_format($day->views)])->all()" :shown="15" />

        <x-rows-table :title="__('Payments')" :unit="__('Live payments and refunds your tools reported with this uid.')"
            :columns="[[__('When'), 'left'], [__('What'), 'left'], [__('Amount'), 'right']]"
            :rows="$payments->map(fn ($payment) => [
                $payment->occurred_at->setTimezone($site->timezone)->translatedFormat('j M Y H:i'),
                $payment->action === ConversionAction::Paid ? __('Paid') : __('Refunded'),
                ['label' => Money::format($payment->action === ConversionAction::Paid ? $payment->amount : -abs($payment->amount), $payment->currency), 'tone' => $payment->action === ConversionAction::Paid ? null : 'bad'],
            ])->all()" :shown="15" :empty="__('No payment with this uid.')" />
    </div>
</x-section-frame>
