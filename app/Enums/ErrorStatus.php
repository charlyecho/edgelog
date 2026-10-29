<?php

namespace App\Enums;

enum ErrorStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    /**
     * Get the human readable name of the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Resolved => __('Resolved'),
            self::Ignored => __('Ignored'),
        };
    }
}
