<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationTool;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SiteIntegrationController extends Controller
{
    /**
     * Save the signing secret of the site's Stripe webhook endpoint.
     */
    public function update(Request $request, Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate([
            'secret' => ['required', 'string', 'starts_with:whsec_', 'max:255'],
        ], [
            'secret.starts_with' => __('A Stripe signing secret starts with whsec_.'),
        ]);

        $site->integrations()->updateOrCreate(
            ['tool' => IntegrationTool::Stripe],
            ['secret' => $validated['secret'], 'last_refused_at' => null, 'last_refusal' => null],
        );

        return back()->with('status', __('Stripe is connected. Payments are counted from the next event.'));
    }

    /**
     * Disconnect Stripe: its address answers 404 from now on. Conversions already kept stay.
     */
    public function destroy(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $site->integrations()->where('tool', IntegrationTool::Stripe)->delete();

        return back()->with('status', __('Stripe is disconnected.'));
    }
}
