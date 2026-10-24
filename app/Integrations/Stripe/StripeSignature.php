<?php

namespace App\Integrations\Stripe;

/**
 * Checks a Stripe webhook's Stripe-Signature header: "t=<unix time>,v1=<hmac>,...",
 * an HMAC-SHA256 of "<t>.<raw body>" with the endpoint's signing secret.
 * Checked on the raw bytes, before the body is read; a signature more than
 * five minutes off is refused, so a captured body cannot be replayed later.
 */
final class StripeSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public static function verify(string $payload, ?string $header, string $secret, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header Stripe would send for this body: for tests and the test button.
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
