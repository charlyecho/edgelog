<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshToday;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteRealtimeController extends Controller
{
    /**
     * Show who is on the site now and what is fetching it, from today's log
     * so far as the queue last counted it.
     */
    public function __invoke(Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $today = RefreshToday::latest($site);

        return view($today === null ? 'sites.realtime-counting' : 'sites.realtime', [
            'team' => $team,
            'site' => $site,
            'live' => $today === null ? null : RefreshToday::snapshot($today),
        ]);
    }
}
