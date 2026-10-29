<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamIconController extends Controller
{
    /**
     * Show the team's icon, as an image that can run nothing.
     */
    public function show(Team $team): StreamedResponse
    {
        Gate::authorize('view', $team);

        return $team->iconResponse();
    }

    /**
     * Keep an uploaded image as the team's icon.
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $request->validate([
            'icon' => ['required', 'file', 'max:'.(Team::ICON_MAX_BYTES / 1024), 'mimes:png,jpg,jpeg,gif,webp,svg,ico'],
        ]);

        if (! $team->storeIcon($request->file('icon')->get())) {
            throw ValidationException::withMessages(['icon' => __('The icon must be a PNG, JPEG, GIF, WebP, SVG or ICO image.')]);
        }

        return back()->with('status', __('Icon saved.'));
    }

    /**
     * Delete the team's icon: it is shown with its initial again.
     */
    public function destroy(Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team->forgetIcon();

        return back()->with('status', __('Icon removed.'));
    }
}
