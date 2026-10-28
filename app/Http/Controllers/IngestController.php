<?php

namespace App\Http\Controllers;

use App\Actions\Ingest\IngestBatch;
use App\Actions\Ingest\IngestHistoryBatch;
use App\Actions\Ingest\IngestServerSample;
use App\Ingest\IngestRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestController extends Controller
{
    /**
     * Take a batch of log lines from a site's shipper.
     */
    public function __invoke(Request $request, string $site, IngestBatch $ingestBatch): JsonResponse
    {
        try {
            $outcome = $ingestBatch->handle($site, $request);
        } catch (IngestRefusal $refusal) {
            return $this->refuse($refusal);
        }

        return $this->answer(200, $outcome->toArray());
    }

    /**
     * Take a part of a past log file from a site's shipper, run with --backfill.
     */
    public function history(Request $request, string $site, IngestHistoryBatch $ingestHistoryBatch): JsonResponse
    {
        try {
            $outcome = $ingestHistoryBatch->handle($site, $request);
        } catch (IngestRefusal $refusal) {
            return $this->refuse($refusal);
        }

        return $this->answer(200, $outcome->toArray());
    }

    /**
     * Take one minute of the site's server, from a shipper run with --metrics.
     */
    public function metrics(Request $request, string $site, IngestServerSample $ingestServerSample): JsonResponse
    {
        try {
            $sample = $ingestServerSample->handle($site, $request);
        } catch (IngestRefusal $refusal) {
            return $this->refuse($refusal);
        }

        return $this->answer(200, ['ok' => true, 'minute' => $sample->minute->toIso8601String()]);
    }

    private function refuse(IngestRefusal $refusal): JsonResponse
    {
        return $this->answer(
            $refusal->reason->status(),
            ['ok' => false, 'reason' => $refusal->reason->value, 'error' => $refusal->getMessage(), ...$refusal->details],
            $refusal->headers,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function answer(int $status, array $body, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }
}
