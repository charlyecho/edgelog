<?php

namespace App\Actions\Ingest;

use App\Enums\IngestRefusalReason;
use App\Ingest\BatchBody;
use App\Ingest\BatchId;
use App\Ingest\IngestLog;
use App\Ingest\IngestLogFull;
use App\Ingest\IngestRefusal;
use App\Ingest\TeamDailyQuota;
use App\Ingest\UtcDay;
use App\Models\IngestStatus;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Take one batch of log lines a shipper posted for a site.
 *
 * In this order, before anything is kept: the token (401) must be the
 * site's own (403, also for an unknown site, so a token cannot probe which
 * sites exist); at most `ingest.per_minute` batches a minute per site (429);
 * enough free disk (507); a body of at most 5 MiB, plain or gzip, packed and
 * unpacked (413, 415, 400); a batch id (400). Then the lines the site's log
 * format cannot read are counted and dropped, the team's bytes for the UTC
 * day are counted (429), and the accepted lines are appended to the site's
 * log (429 when today's file is full). A batch kept before is acknowledged
 * as a duplicate and not written again.
 *
 * Nothing about the sender is kept: no address, no user agent.
 */
class IngestBatch
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, Request $request): IngestOutcome
    {
        try {
            return $this->ingest($siteUuid, $request);
        } catch (IngestRefusal $refusal) {
            $site = Site::query()->where('uuid', $siteUuid)->first();

            if ($site !== null) {
                IngestStatus::recordRefusal($site, $refusal->reason);
            }

            throw $refusal;
        }
    }

    /**
     * @throws IngestRefusal
     */
    private function ingest(string $siteUuid, Request $request): IngestOutcome
    {
        $site = $this->authenticate($siteUuid, $request->bearerToken());

        $this->throttle($site);
        $this->ensureFreeDisk();

        $maxBody = (int) config('ingest.max_body');

        if ((int) $request->header('Content-Length') > $maxBody) {
            throw new IngestRefusal(IngestRefusalReason::TooLarge);
        }

        $body = BatchBody::unpack($request->getContent(), $request->header('Content-Encoding'), $maxBody);
        $batch = BatchId::tryFrom($request->header(BatchId::HEADER)) ?? throw new IngestRefusal(IngestRefusalReason::BadBatch);
        $log = IngestLog::for($site);

        if ($log->hasSeen($batch)) {
            return IngestOutcome::duplicate();
        }

        $lines = $body->accept($site->log_format->parser(), (int) config('ingest.max_line'));
        $quota = TeamDailyQuota::for($site->team);

        if (! $quota->reserve($lines->bytes())) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::TeamLimit, UtcDay::retryAfter());
        }

        try {
            $stored = $log->append($batch, $lines->data);
        } catch (IngestLogFull) {
            $quota->release($lines->bytes());

            throw IngestRefusal::retryAfter(IngestRefusalReason::DayFull, UtcDay::retryAfter());
        }

        if (! $stored) {
            $quota->release($lines->bytes());

            return IngestOutcome::duplicate();
        }

        IngestStatus::recordBatch($site, $batch, $lines->accepted, $lines->rejected);

        return new IngestOutcome($lines->accepted, $lines->rejected);
    }

    /**
     * @throws IngestRefusal
     */
    private function authenticate(string $siteUuid, ?string $token): Site
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
     * @throws IngestRefusal
     */
    private function throttle(Site $site): void
    {
        $key = "ingest:{$site->id}";

        if (RateLimiter::tooManyAttempts($key, (int) config('ingest.per_minute'))) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::RateLimited, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @throws IngestRefusal
     */
    private function ensureFreeDisk(): void
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
}
