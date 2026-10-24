<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SiteTokenController extends Controller
{
    /**
     * Replace the site's token; the previous one stops working at once.
     */
    public function store(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $token = $site->fillNewToken();
        $site->save();

        return back()
            ->with('status', __('New token generated. The previous one no longer works.'))
            ->with('site_token', $token);
    }
}
