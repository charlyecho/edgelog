<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshLiveSnapshot;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteRealtimeController extends Controller
{
    /**
     * Show who is on the site now and what is fetching it, from today's log so far.
     */
    public function __invoke(Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        return view('sites.realtime', [
            'team' => $team,
            'site' => $site,
            'live' => RefreshLiveSnapshot::latest($site),
        ]);
    }
}
