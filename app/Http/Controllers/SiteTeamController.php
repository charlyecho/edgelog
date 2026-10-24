<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SiteTeamController extends Controller
{
    /**
     * Move the site to another team of the user.
     */
    public function update(Request $request, Team $team, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'team' => ['required', 'string'],
        ]);

        $target = $request->user()->teams()->where('uuid', $validated['team'])->firstOrFail();

        Gate::authorize('transfer', [$site, $target]);

        $site->team()->associate($target)->save();

        return redirect()
            ->route('teams.sites.edit', [$target, $site])
            ->with('status', __(':site now belongs to :team.', ['site' => $site->name, 'team' => $target->name]));
    }
}
