<?php

namespace App\Actions\Ingest;

use App\Enums\ErrorLogKind;
use App\Enums\IngestRefusalReason;
use App\Ingest\BatchId;
use App\Ingest\Errors\ErrorEntries;
use App\Ingest\Errors\ErrorRecorder;
use App\Ingest\IngestGate;
use App\Ingest\IngestRefusal;
use App\Ingest\TeamDailyQuota;
use App\Ingest\UtcDay;
use App\Models\Site;
use App\Models\SiteErrorStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Take one batch of an application's error log, posted by edgelog-ship
 * --errors <kind>.
 *
 * The checks of IngestGate and a batch id (400) first, then the team's bytes
 * for the UTC day (429). The batch is read after the entry held back from the
 * batch before; its last entry is held in turn, as its stack trace may still
 * be on its way. Each error or exception read is kept (ErrorRecorder);
 * anything else (warnings, notices, lines of another format) is counted as
 * rejected and dropped. A batch kept before is acknowledged as a duplicate.
 */
class IngestErrorBatch
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, ErrorLogKind $kind, Request $request): IngestOutcome
    {
        $site = IngestGate::site($siteUuid, $request->bearerToken());

        IngestGate::throttle($site, 'errors');

        $body = IngestGate::body($request);
        $batch = BatchId::tryFrom($request->header(BatchId::HEADER)) ?? throw new IngestRefusal(IngestRefusalReason::BadBatch);

        return Cache::lock("errors:{$site->id}:{$kind->value}:{$batch->shipper}", 30)
            ->block(10, fn () => $this->ingest($site, $kind, $batch, $body->lines));
    }

    /**
     * @throws IngestRefusal
     */
    private function ingest(Site $site, ErrorLogKind $kind, BatchId $batch, string $lines): IngestOutcome
    {
        $stream = $site->errorStreams()->firstOrCreate(['kind' => $kind, 'shipper' => $batch->shipper]);

        if ($batch->seq <= $stream->last_seq) {
            return IngestOutcome::duplicate();
        }

        $quota = TeamDailyQuota::for($site->team);

        if (! $quota->reserve(strlen($lines))) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::TeamLimit, UtcDay::retryAfter());
        }

        $read = ErrorEntries::split($kind->parser(), ($stream->tail ?? '')."\n".$lines);

        return DB::transaction(function () use ($site, $kind, $batch, $stream, $read) {
            [$accepted, $rejected] = self::record($site, $kind, $read->entries);

            $stream->update([
                'last_seq' => $batch->seq,
                'tail' => $read->tail === '' ? null : $read->tail,
                'tail_at' => $read->tail === '' ? null : now(),
            ]);

            return new IngestOutcome($accepted, $rejected);
        });
    }

    /**
     * Read the tails that waited long enough for the rest of their entry:
     * their log has said nothing since.
     */
    public static function flushTails(int $afterSeconds): int
    {
        $flushed = 0;

        SiteErrorStream::query()->with('site')->whereNotNull('tail')->where('tail_at', '<=', now()->subSeconds($afterSeconds))
            ->each(function (SiteErrorStream $stream) use (&$flushed) {
                Cache::lock("errors:{$stream->site_id}:{$stream->kind->value}:{$stream->shipper}", 30)->block(10, function () use ($stream, &$flushed) {
                    $stream->refresh();

                    if ($stream->tail === null) {
                        return;
                    }

                    DB::transaction(function () use ($stream) {
                        self::record($stream->site, $stream->kind, [$stream->tail]);
                        $stream->update(['tail' => null, 'tail_at' => null]);
                    });

                    $flushed++;
                });
            });

        return $flushed;
    }

    /**
     * Keep the errors among the entries.
     *
     * @param  list<string>  $entries
     * @return array{0: int, 1: int} the entries kept, and those that were not errors
     */
    private static function record(Site $site, ErrorLogKind $kind, array $entries): array
    {
        $parser = $kind->parser();
        $recorder = new ErrorRecorder($site, $kind);
        $accepted = 0;

        foreach ($entries as $text) {
            $entry = $parser->parse($text);

            if ($entry !== null) {
                $recorder->record($entry);
                $accepted++;
            }
        }

        return [$accepted, count($entries) - $accepted];
    }
}
