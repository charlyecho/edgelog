<?php

namespace App\Enums;

enum IngestRefusalReason: string
{
    case NoToken = 'no_token';
    case BadToken = 'bad_token';
    case WrongSite = 'wrong_site';
    case RateLimited = 'rate_limited';
    case DayFull = 'day_full';
    case TeamLimit = 'team_limit';
    case Disk = 'disk';
    case BadBatch = 'bad_batch';
    case BadBody = 'bad_body';
    case BadEncoding = 'bad_encoding';
    case TooLarge = 'too_large';

    /**
     * Get the HTTP status the endpoint answers with.
     */
    public function status(): int
    {
        return match ($this) {
            self::NoToken, self::BadToken => 401,
            self::WrongSite => 403,
            self::RateLimited, self::DayFull, self::TeamLimit => 429,
            self::Disk => 507,
            self::BadBatch, self::BadBody => 400,
            self::BadEncoding => 415,
            self::TooLarge => 413,
        };
    }

    /**
     * Get the plain words that explain the refusal.
     */
    public function words(): string
    {
        return match ($this) {
            self::NoToken => __('No token was sent. Send the site token as Authorization: Bearer <token>.'),
            self::BadToken => __('The token is not valid. It may have been replaced by a new one.'),
            self::WrongSite => __('The token is not for this site.'),
            self::RateLimited => __('Too many batches for this site; slow down.'),
            self::DayFull => __('Today\'s log for this site is full. Nothing is lost: the shipper keeps the lines and tries again after midnight UTC.'),
            self::TeamLimit => __('The team\'s log lines for today are used up. Nothing is lost: the shipper keeps the lines and tries again after midnight UTC.'),
            self::Disk => __('The server is short of disk space, so no lines are taken now. Nothing is lost: the shipper keeps the lines and tries again.'),
            self::BadBatch => __('X-Edgelog-Batch: <shipper id>:<seq> is needed.'),
            self::BadBody => __('The gzip body could not be read.'),
            self::BadEncoding => __('Send the body plain or with Content-Encoding: gzip.'),
            self::TooLarge => __('A batch is at most 5 MiB, packed and unpacked.'),
        };
    }
}
