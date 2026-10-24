<?php

namespace App\Http\Controllers;

use App\Actions\Ingest\IngestBatch;
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
            return $this->answer(
                $refusal->reason->status(),
                ['ok' => false, 'reason' => $refusal->reason->value, 'error' => $refusal->getMessage()],
                $refusal->headers,
            );
        }

        return $this->answer(200, $outcome->toArray());
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
