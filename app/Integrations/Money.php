<?php

namespace App\Integrations;

use NumberFormatter;

/**
 * Amounts as payment tools send them: whole numbers in a currency's minor
 * unit (cents for EUR, yen for JPY, fils for KWD). Currencies are never added
 * together: a figure is always one currency's.
 */
final class Money
{
    /**
     * Currencies without a minor unit.
     *
     * @var list<string>
     */
    private const array ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /**
     * Currencies with three decimals.
     *
     * @var list<string>
     */
    private const array THREE_DECIMAL = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    public static function decimals(string $currency): int
    {
        $currency = strtolower($currency);

        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    /**
     * An amount in minor units, written for a reader: "€1,234.50".
     */
    public static function format(int $minor, string $currency, ?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($minor / 10 ** self::decimals($currency), strtoupper($currency)) ?: "{$minor} {$currency}";
    }
}
