<?php

namespace App\Http\Controllers;

use App\Analytics\PathPatterns;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use App\Models\Team;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;

class SitePathsController extends Controller
{
    /**
     * The recent days a reader may have counted again with the new patterns.
     */
    public const int RECOUNT_DAYS = 7;

    /**
     * The site's lists of paths: those its pages call while they are read
     * (never pages), and those it really has that scanners ask for too
     * (never probes).
     */
    public const array LISTS = ['not_pages', 'own_paths'];

    /**
     * Save the site's lists of paths, and count the last days again with
     * them when asked: oldest first, in one chain, so each day knows the
     * visitors of the days before it.
     */
    public function update(Request $request, Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $request->validate([
            ...array_fill_keys(self::LISTS, ['nullable', 'string', 'max:5000', $this->patternsRule(...)]),
            'recount' => ['boolean'],
        ]);

        $lists = [];

        foreach (self::LISTS as $list) {
            $patterns = PathPatterns::parse((string) $request->input($list));
            $lists[$list] = $patterns === [] ? null : $patterns;
        }

        $site->update($lists);

        if (! $request->boolean('recount')) {
            return back()->with('status', __('Saved. Days counted from now on follow these paths.'));
        }

        $yesterday = now($site->timezone)->subDay()->toImmutable();
        $jobs = [];

        for ($n = self::RECOUNT_DAYS - 1; $n >= 0; $n--) {
            $day = $yesterday->subDays($n)->toDateString();
            array_push($jobs, new CollectSiteDay($site, $day), new AuditSiteDay($site, $day));
        }

        Bus::chain($jobs)->dispatch();

        return back()->with('status', __('Saved. The last :count days are being counted again with these paths.', ['count' => self::RECOUNT_DAYS]));
    }

    private function patternsRule(string $attribute, mixed $value, Closure $fail): void
    {
        $patterns = PathPatterns::parse((string) $value);

        if (count($patterns) > PathPatterns::MAX) {
            $fail(__('At most :count paths.', ['count' => PathPatterns::MAX]));
        }

        foreach ($patterns as $pattern) {
            if (! PathPatterns::isValid($pattern)) {
                $fail(__(':pattern is not a path: start it with / (or *), with no spaces, and leave some pages pages.', ['pattern' => $pattern]));
            }
        }
    }
}
