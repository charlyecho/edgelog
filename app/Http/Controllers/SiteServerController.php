<?php

namespace App\Http\Controllers;

use App\Analytics\Server\ServerRange;
use App\Ingest\ShipperSetup;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteServerController extends Controller
{
    /**
     * The site's server as edgelog-ship --metrics reports it: its last
     * minute, its disks, and its processors, memory, load, network and disks
     * over the range picked.
     */
    public function __invoke(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        return view('sites.site.server', [
            'team' => $team,
            'site' => $site,
            'server' => ServerRange::last($site, ServerRange::choose($request->query('range'))),
            'setup' => new ShipperSetup($site),
        ]);
    }
}
