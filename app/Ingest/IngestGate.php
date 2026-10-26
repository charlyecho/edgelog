<?php

namespace App\Ingest;

use App\Enums\IngestRefusalReason;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The checks every posted batch passes before anything is kept, live or
 * past: the token (401) must be the site's own (403, also for an unknown
 * site, so a token cannot probe which sites exist); at most
 * `ingest.per_minute` batches a minute per site and kind (429); enough free
 * disk (507); a body of at most 5 MiB, plain or gzip, packed and unpacked
 * (413, 415, 400).
 */
final class IngestGate
{
    /**
     * The site the batch is for, once the token says it may send to it.
     *
     * @throws IngestRefusal
     */
    public static function site(string $siteUuid, ?string $token): Site
    {
        if ($token === null || $token === '') {
            throw new IngestRefusal(IngestRefusalReason::NoToken, ['WWW-Authenticate' => 'Bearer']);
        }

        $site = Site::findByToken($token) ?? throw new IngestRefusal(IngestRefusalReason::BadToken, ['WWW-Authenticate' => 'Bearer']);

        if (! hash_equals($site->uuid, $siteUuid)) {
            throw new IngestRefusal(IngestRefusalReason::WrongSite);
        }

        return $site;
    }

    /**
     * Count the batch against the site's batches a minute of the given kind.
     *
     * @throws IngestRefusal
     */
    public static function throttle(Site $site, string $kind = 'ingest'): void
    {
        $key = "{$kind}:{$site->id}";

        if (RateLimiter::tooManyAttempts($key, (int) config('ingest.per_minute'))) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::RateLimited, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @throws IngestRefusal
     */
    public static function ensureFreeDisk(): void
    {
        $path = config('ingest.root');

        while (! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        $free = @disk_free_space($path);

        if ($free !== false && $free < config('ingest.min_free_gb') * 1024 ** 3) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::Disk, 600);
        }
    }

    /**
     * The request's body, unpacked.
     *
     * @throws IngestRefusal
     */
    public static function body(Request $request): BatchBody
    {
        $maxBody = (int) config('ingest.max_body');

        if ((int) $request->header('Content-Length') > $maxBody) {
            throw new IngestRefusal(IngestRefusalReason::TooLarge);
        }

        return BatchBody::unpack($request->getContent(), $request->header('Content-Encoding'), $maxBody);
    }
}
