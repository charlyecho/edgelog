<?php

namespace App\Analytics\Audit;

use App\Analytics\DayWindow;
use Illuminate\Support\Facades\DB;

/**
 * The day's money, counted again from the verified webhook events as they
 * were received, by its own reading of them: it never calls the code that
 * made the conversions. When the two disagree, the day is withheld.
 */
final class RevenueRecount
{
    public function __construct(private readonly int $siteId, private readonly DayWindow $window) {}

    /**
     * @return array<string, int> keyed as DayFigures::core() keys the money
     */
    public function figures(): array
    {
        $out = [];
        $inWindow = $this->events($this->window->start, $this->window->end);
        $candidates = [];

        foreach ($inWindow as $event) {
            $money = $this->moneyOf($event);

            if ($money !== null) {
                $candidates[$money['once']] ??= $money;
            }
        }

        // A conversion belongs to the day of its first event: one an earlier
        // day already counted is not counted again.
        $ids = array_unique(array_column($candidates, 'id'));

        if ($ids !== []) {
            foreach ($this->events(null, $this->window->start, $ids) as $earlier) {
                $money = $this->moneyOf($earlier);

                if ($money !== null) {
                    unset($candidates[$money['once']]);
                }
            }
        }

        foreach ($candidates as $money) {
            $out["{$money['kind']}.{$money['currency']}"] = ($out["{$money['kind']}.{$money['currency']}"] ?? 0) + $money['amount'];

            if ($money['kind'] === 'revenue_paid') {
                $out["payments.{$money['currency']}"] = ($out["payments.{$money['currency']}"] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * The site's live Stripe events between two instants, oldest first.
     *
     * @param  ?list<string>  $objectIds  only the events about these objects
     * @return list<array<string, mixed>>
     */
    private function events(?object $from, object $until, ?array $objectIds = null): array
    {
        $query = DB::table('hook_events')
            ->where('site_id', $this->siteId)
            ->where('tool', 'stripe')
            ->where('livemode', true)
            ->where('occurred_at', '<', $until)
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($objectIds !== null) {
            $query->whereIn(DB::raw("json_extract(payload, '$.data.object.id')"), $objectIds);
        }

        return $query->pluck('payload')->map(fn (string $json) => json_decode($json, true))->filter(fn (mixed $event) => is_array($event))->values()->all();
    }

    /**
     * What one event is worth, read by this recount's own rules, or null.
     *
     * @param  array<string, mixed>  $event
     * @return ?array{once: string, id: string, kind: string, currency: string, amount: int}
     */
    private function moneyOf(array $event): ?array
    {
        $object = $event['data']['object'] ?? [];
        $type = $event['type'] ?? '';
        $currency = strtolower($object['currency'] ?? '');

        if (strlen($currency) !== 3 || empty($object['id'])) {
            return null;
        }

        $paidSession = in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && ($object['mode'] ?? '') === 'payment' && ($object['payment_status'] ?? '') === 'paid';
        $subscription = $object['parent']['subscription_details']['subscription'] ?? ($object['subscription'] ?? '');
        $paidInvoice = $type === 'invoice.paid' && is_string($subscription) && $subscription !== '';
        $refund = in_array($type, ['refund.created', 'refund.updated'], true) && ($object['status'] ?? '') === 'succeeded';

        $amount = match (true) {
            $paidSession => (int) ($object['amount_total'] ?? 0),
            $paidInvoice => (int) ($object['amount_paid'] ?? 0),
            $refund => abs((int) ($object['amount'] ?? 0)),
            default => 0,
        };

        if ($amount === 0) {
            return null;
        }

        $kind = $refund ? 'revenue_refunded' : 'revenue_paid';

        return ['once' => "{$kind}|{$object['id']}", 'id' => (string) $object['id'], 'kind' => $kind, 'currency' => $currency, 'amount' => $amount];
    }
}
