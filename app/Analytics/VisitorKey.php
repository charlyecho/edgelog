<?php

namespace App\Analytics;

/**
 * A reader's keyed hash, to tell a returning visitor from a new one
 * without keeping an address: an HMAC-SHA256 of the address with a secret,
 * joined with the browser family and system. Without the secret it cannot
 * be reversed, nor recomputed from a list of addresses.
 */
final class VisitorKey
{
    public static function of(string $address, string $userAgent): string
    {
        $mac = hash_hmac('sha256', $address, self::secret());
        $family = UserAgentLabels::browser($userAgent).'/'.UserAgentLabels::system($userAgent);

        return substr(hash('sha256', "{$mac}|{$family}"), 0, 32);
    }

    /**
     * analytics.visitor_key, or one drawn from the application key: the
     * same secret on every run, so a visitor is known again the next day.
     */
    private static function secret(): string
    {
        $key = (string) config('analytics.visitor_key');

        return $key !== '' ? $key : hash_hmac('sha256', 'edgelog visitors', (string) config('app.key'));
    }
}
