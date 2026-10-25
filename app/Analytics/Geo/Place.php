<?php

namespace App\Analytics\Geo;

/**
 * Where an address is: a two-letter country code, and a city with its
 * region when the city file knows one. An empty field is not recorded,
 * never guessed.
 */
final readonly class Place
{
    public function __construct(public string $country = '', public string $city = '', public string $region = '') {}
}
