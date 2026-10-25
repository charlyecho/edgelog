<?php

namespace App\Analytics\Geo;

use MaxMind\Db\Reader;
use Throwable;

/**
 * Places read from MaxMind-format (.mmdb) files: DB-IP's free "IP to
 * Country Lite" and "IP to City Lite" (CC BY 4.0), which
 * edgelog:geoip-update keeps up to date. The city file answers both when
 * it is there; without either file nothing is recorded.
 *
 * Each address is looked up once per run: a visitor's requests share it.
 */
final class MmdbPlaces implements Places
{
    /**
     * Addresses kept in memory before the oldest are forgotten.
     */
    private const int CACHE = 50_000;

    private ?Reader $countries;

    private ?Reader $cities;

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private array $seen = [];

    public function __construct(?string $countryFile, ?string $cityFile)
    {
        $this->countries = self::open($countryFile);
        $this->cities = self::open($cityFile);
    }

    /**
     * The files analytics.geoip names.
     */
    public static function fromConfig(): self
    {
        return new self(config('analytics.geoip.country'), config('analytics.geoip.city'));
    }

    public function isAvailable(): bool
    {
        return $this->countries !== null || $this->cities !== null;
    }

    public function of(string $address, string $loggedCountry = ''): Place
    {
        [$country, $city] = $this->seen[$address] ??= $this->lookUp($address);

        if (count($this->seen) > self::CACHE) {
            $this->seen = array_slice($this->seen, intdiv(self::CACHE, 2), null, true);
        }

        $loggedCountry = strtoupper($loggedCountry);

        if ($loggedCountry !== '' && $loggedCountry !== $country) {
            return new Place($loggedCountry);
        }

        return new Place($country, $city);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function lookUp(string $address): array
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['', ''];
        }

        try {
            $record = $this->cities?->get($address) ?? $this->countries?->get($address);
        } catch (Throwable) {
            return ['', ''];
        }

        $country = strtoupper((string) ($record['country']['iso_code'] ?? ''));
        $city = (string) ($record['city']['names']['en'] ?? '');

        return [preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '', substr($city, 0, 80)];
    }

    private static function open(?string $file): ?Reader
    {
        if ($file === null || $file === '' || ! is_file($file)) {
            return null;
        }

        try {
            return new Reader($file);
        } catch (Throwable) {
            return null;
        }
    }
}
