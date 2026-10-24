<?php

namespace App\Enums;

/**
 * What a conversion is: money in, or money given back.
 */
enum ConversionAction: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Paid => __('Paid'),
            self::Refunded => __('Refunded'),
        };
    }
}
