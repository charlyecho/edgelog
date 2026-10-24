<?php

namespace App\Integrations\Stripe;

use App\Enums\ConversionAction;
use Carbon\CarbonImmutable;

/**
 * Which Stripe events count, which of their fields are kept, and the
 * conversion each one is.
 *
 * - A one-time payment: its Checkout Session (mode "payment"), once paid,
 *   whether at once (checkout.session.completed) or later by a delayed
 *   method (checkout.session.async_payment_succeeded).
 * - A subscription's payments, the first one included: invoice.paid for an
 *   invoice of a subscription. Its Checkout Session (mode "subscription")
 *   is not counted, or the first payment would count twice.
 * - A refund, once it succeeded: refund.created or refund.updated, one per
 *   refund (charge.refunded holds a running total, which would split badly).
 */
final class StripeEvents
{
    /**
     * @var list<string>
     */
    public const array TYPES = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'invoice.paid',
        'refund.created',
        'refund.updated',
    ];

    /**
     * Keep only the fields the counts read: never the customer's name, email or address.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function keep(array $event): array
    {
        $object = $event['data']['object'] ?? [];
        $kept = array_intersect_key($object, array_flip([
            'object', 'id', 'mode', 'payment_status', 'amount_total', 'amount_paid', 'amount',
            'currency', 'status', 'subscription', 'billing_reason', 'client_reference_id',
        ]));

        if (isset($object['metadata']['uid'])) {
            $kept['metadata'] = ['uid' => $object['metadata']['uid']];
        }

        $details = $object['parent']['subscription_details'] ?? [];

        if (isset($details['subscription'])) {
            $kept['parent']['subscription_details']['subscription'] = $details['subscription'];
        }

        if (isset($details['metadata']['uid'])) {
            $kept['parent']['subscription_details']['metadata'] = ['uid' => $details['metadata']['uid']];
        }

        return [
            'id' => $event['id'] ?? null,
            'type' => $event['type'] ?? null,
            'created' => $event['created'] ?? null,
            'livemode' => (bool) ($event['livemode'] ?? false),
            'data' => ['object' => $kept],
        ];
    }

    /**
     * The conversion a kept event is, or null when it counts for nothing.
     *
     * @param  array<string, mixed>  $event
     * @return ?array{external_ref: string, action: ConversionAction, amount: int, currency: string, livemode: bool, occurred_at: CarbonImmutable, uid: ?string}
     */
    public static function conversion(array $event): ?array
    {
        $object = $event['data']['object'] ?? [];
        $currency = strtolower((string) ($object['currency'] ?? ''));

        if (preg_match('/^[a-z]{3}$/', $currency) !== 1 || ! isset($object['id'], $event['created'])) {
            return null;
        }

        [$action, $amount] = match ($event['type'] ?? null) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['mode'] ?? null) === 'payment' && ($object['payment_status'] ?? null) === 'paid'
                ? [ConversionAction::Paid, (int) ($object['amount_total'] ?? 0)]
                : [null, 0],
            'invoice.paid' => self::subscriptionOf($object) !== null
                ? [ConversionAction::Paid, (int) ($object['amount_paid'] ?? 0)]
                : [null, 0],
            'refund.created', 'refund.updated' => ($object['status'] ?? null) === 'succeeded'
                ? [ConversionAction::Refunded, -abs((int) ($object['amount'] ?? 0))]
                : [null, 0],
            default => [null, 0],
        };

        if ($action === null || $amount === 0) {
            return null;
        }

        return [
            'external_ref' => (string) $object['id'],
            'action' => $action,
            'amount' => $amount,
            'currency' => $currency,
            'livemode' => (bool) ($event['livemode'] ?? false),
            'occurred_at' => CarbonImmutable::createFromTimestamp((int) $event['created']),
            'uid' => self::uidOf($object),
        ];
    }

    /**
     * The signed-in user the site's app passed to Stripe: the Checkout
     * Session's client_reference_id or metadata.uid, or the subscription's
     * metadata.uid that Stripe copies onto its invoices.
     *
     * @param  array<string, mixed>  $object
     */
    public static function uidOf(array $object): ?string
    {
        foreach ([
            $object['metadata']['uid'] ?? null,
            $object['client_reference_id'] ?? null,
            $object['parent']['subscription_details']['metadata']['uid'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The subscription an invoice belongs to: on the invoice itself before
     * API version 2025-03-31, under parent.subscription_details since.
     *
     * @param  array<string, mixed>  $invoice
     */
    private static function subscriptionOf(array $invoice): ?string
    {
        $subscription = $invoice['parent']['subscription_details']['subscription'] ?? $invoice['subscription'] ?? null;

        return is_string($subscription) && $subscription !== '' ? $subscription : null;
    }
}
