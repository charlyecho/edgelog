<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SiteTeamController extends Controller
{
    /**
     * Move the site to another team of the user. Its server stays with the
     * team it belongs to: the shipper's next sample ties the site to one of
     * the new team. The viewers of the old team it was granted to lose it.
     */
    public function update(Request $request, Team $team, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'team' => ['required', 'string'],
        ]);

        $target = $request->user()->teams()->where('uuid', $validated['team'])->firstOrFail();

        Gate::authorize('transfer', [$site, $target]);

        DB::transaction(function () use ($site, $target) {
            $site->team()->associate($target)->server()->dissociate()->save();
            DB::table('membership_site')->where('site_id', $site->id)->delete();
        });

        return redirect()
            ->route('teams.sites.edit', [$target, $site])
            ->with('status', __(':site now belongs to :team.', ['site' => $site->name, 'team' => $target->name]));
    }
}
