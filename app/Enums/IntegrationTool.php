<?php

namespace App\Enums;

/**
 * A tool that pushes signed webhooks about a site.
 */
enum IntegrationTool: string
{
    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
        };
    }
}
