<?php

namespace App\Http\Controllers;

use App\Jobs\FetchSiteIcon;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteIconController extends Controller
{
    /**
     * Show the site's icon, as an image that can run nothing.
     */
    public function show(Team $team, Site $site): StreamedResponse
    {
        Gate::authorize('view', $site);

        abort_if($site->icon_path === null || ! Storage::disk('local')->exists($site->icon_path), 404);

        $type = array_search(pathinfo($site->icon_path, PATHINFO_EXTENSION), Site::ICON_TYPES, true);

        return Storage::disk('local')->response($site->icon_path, null, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Keep an uploaded image as the site's icon.
     */
    public function store(Request $request, Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $request->validate([
            'icon' => ['required', 'file', 'max:'.(Site::ICON_MAX_BYTES / 1024), 'mimes:png,jpg,jpeg,gif,webp,svg,ico'],
        ]);

        if (! $site->storeIcon($request->file('icon')->get(), uploaded: true)) {
            throw ValidationException::withMessages(['icon' => __('The icon must be a PNG, JPEG, GIF, WebP, SVG or ICO image.')]);
        }

        return back()->with('status', __('Icon saved.'));
    }

    /**
     * Read the site's icon from the site again, in place of the current one.
     */
    public function fetch(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $found = (new FetchSiteIcon($site, replaceUploaded: true))->handle();

        return back()->with('status', $found ? __('Icon read from the site.') : __('No icon could be read from the site.'));
    }

    /**
     * Delete the site's icon: it is shown with its initial again.
     */
    public function destroy(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $site->forgetIcon();

        return back()->with('status', __('Icon removed.'));
    }
}
