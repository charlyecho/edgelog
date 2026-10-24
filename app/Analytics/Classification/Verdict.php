<?php

namespace App\Analytics\Classification;

use App\Enums\EngineTier;
use App\Enums\TrafficGroup;

/**
 * What a request is, and why.
 */
final readonly class Verdict
{
    public function __construct(
        public TrafficGroup $group,
        public string $name = '',
        public ?EngineTier $tier = null,
        public bool $probe = false,
        public string $citedBy = '',
    ) {}
}
