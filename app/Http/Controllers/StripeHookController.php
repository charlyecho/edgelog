<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationTool;
use App\Integrations\Stripe\StripeEvents;
use App\Integrations\Stripe\StripeSignature;
use App\Jobs\ProcessHookEvent;
use App\Models\Site;
use App\Models\SiteIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class StripeHookController extends Controller
{
    /**
     * The largest body taken, in bytes: an invoice with many lines stays well under it.
     */
    public const int MAX_BODY = 512 * 1024;

    /**
     * Take a Stripe webhook event for a site.
     *
     * The signature is checked on the raw body before anything else, so an
     * unsigned post neither reads the body nor spends the site's rate limit.
     * A verified event is kept once (Stripe sends again until it gets a 2xx)
     * and turned into a conversion on the queue.
     */
    public function __invoke(Request $request, string $site): JsonResponse
    {
        $integration = SiteIntegration::query()
            ->where('tool', IntegrationTool::Stripe)
            ->whereHas('site', fn ($query) => $query->where('uuid', $site))
            ->first();

        if ($integration === null) {
            return $this->answer(404, ['ok' => false, 'error' => 'not found']);
        }

        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BODY) {
            return $this->refuse($integration, 413, __('The event is larger than 512 KiB.'));
        }

        if (! StripeSignature::verify($payload, $request->header('Stripe-Signature'), $integration->secret)) {
            return $this->refuse($integration, 401, __('The signature does not match the signing secret, or is more than 5 minutes old.'));
        }

        $key = "stripe-hook:{$integration->site_id}";

        if (RateLimiter::tooManyAttempts($key, 120)) {
            return $this->answer(429, ['ok' => false, 'error' => 'too many events'], ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);
        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ! is_int($event['created'] ?? null)) {
            return $this->refuse($integration, 400, __('The body is not a Stripe event.'));
        }

        $integration->update(['last_received_at' => now()]);

        if (! in_array($event['type'], StripeEvents::TYPES, true)) {
            return $this->answer(200, ['ok' => true, 'ignored' => true]);
        }

        $kept = StripeEvents::keep($event);
        $hookEvent = $integration->site->hookEvents()->firstOrCreate(
            ['tool' => IntegrationTool::Stripe, 'event_id' => $event['id']],
            [
                'type' => $event['type'],
                'livemode' => $kept['livemode'],
                'occurred_at' => CarbonImmutable::createFromTimestamp($event['created']),
                'payload' => $kept,
            ],
        );

        if (! $hookEvent->wasRecentlyCreated) {
            return $this->answer(200, ['ok' => true, 'duplicate' => true]);
        }

        ProcessHookEvent::dispatch($hookEvent);

        return $this->answer(200, ['ok' => true, 'duplicate' => false]);
    }

    /**
     * Note a refusal, at most once a second per site, for the settings page.
     */
    private function refuse(SiteIntegration $integration, int $status, string $why): JsonResponse
    {
        if (Cache::add("stripe-hook-refusal:{$integration->id}", true, 1)) {
            $integration->update(['last_refused_at' => now(), 'last_refusal' => "{$status}: {$why}"]);
        }

        return $this->answer($status, ['ok' => false, 'error' => $why]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function answer(int $status, array $body, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
