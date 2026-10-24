<?php

namespace App\Jobs;

use App\Analytics\DayWindow;
use App\Integrations\Stripe\StripeEvents;
use App\Models\HookEvent;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Turn a verified webhook event into a conversion, once. When the event
 * belongs to a day already counted (Stripe sends late events again for
 * three days), that day is counted and audited again.
 */
class ProcessHookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public HookEvent $event) {}

    public function handle(): void
    {
        $conversion = StripeEvents::conversion($this->event->payload);
        $site = $this->event->site;

        if ($conversion !== null) {
            $stored = $site->conversions()->createOrFirst(
                ['tool' => $this->event->tool, 'external_ref' => $conversion['external_ref'], 'action' => $conversion['action']],
                ['amount' => $conversion['amount'], 'currency' => $conversion['currency'], 'livemode' => $conversion['livemode'], 'occurred_at' => $conversion['occurred_at'], 'uid' => $conversion['uid']],
            );

            $days = [];

            if ($stored->wasRecentlyCreated) {
                $days[] = $conversion['occurred_at'];
            } elseif ($conversion['occurred_at']->lessThan($stored->occurred_at)) {
                // An earlier event of the same payment arrived late: the
                // conversion belongs to the day of its first event.
                $days = [$stored->occurred_at->toImmutable(), $conversion['occurred_at']];
                $stored->update(['occurred_at' => $conversion['occurred_at']]);
            }

            if ($conversion['livemode']) {
                foreach ($days as $when) {
                    $this->countAgain($site, $when->setTimezone($site->timezone)->toDateString());
                }
            }
        }

        $this->event->update(['processed_at' => now()]);
    }

    /**
     * Count a day again when it was already counted and is over.
     */
    private function countAgain(Site $site, string $day): void
    {
        if ($site->days()->whereDate('day', $day)->exists() && DayWindow::for($site, $day)->isOver()) {
            Bus::chain([new CollectSiteDay($site, $day), new AuditSiteDay($site, $day)])->dispatch();
        }
    }
}
