<?php

namespace App\Http\Controllers;

use App\Enums\ErrorLogKind;
use App\Enums\ErrorStatus;
use App\Ingest\ShipperSetup;
use App\Models\Site;
use App\Models\SiteError;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteErrorsController extends Controller
{
    public const int PER_PAGE = 50;

    /**
     * The errors of the site's application logs with the status picked, the
     * last seen first: how often each came in the last day and month.
     */
    public function index(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $status = ErrorStatus::tryFrom((string) $request->query('status')) ?? ErrorStatus::Open;
        $errors = $site->errors()->where('status', $status)->orderByDesc('last_seen_at')->paginate(self::PER_PAGE)->withQueryString();
        $dayAgo = now()->subDay();
        $monthAgo = now()->subDays((int) config('ingest.error_occurrence_days'));

        $recent = $site->errorOccurrences()
            ->whereIn('site_error_id', $errors->pluck('id'))
            ->where('at', '>=', $monthAgo)
            ->groupBy('site_error_id')
            ->selectRaw('site_error_id, count(*) as month, sum(case when at >= ? then 1 else 0 end) as day', [$dayAgo])
            ->toBase()
            ->get()
            ->keyBy('site_error_id');

        return view('sites.site.errors', [
            'team' => $team,
            'site' => $site,
            'status' => $status,
            'errors' => $errors,
            'recent' => $recent,
            'counts' => $site->errors()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status'),
            'lastDay' => $site->errorOccurrences()->where('at', '>=', $dayAgo)->count(),
            'newLastDay' => $site->errors()->where('first_seen_at', '>=', $dayAgo)->count(),
            'shipping' => $site->errorStreams()->exists(),
            'setup' => new ShipperSetup($site),
            'kinds' => ErrorLogKind::cases(),
        ]);
    }

    /**
     * One error: its message and stack trace, the pages and referrers it came
     * from, each day of the last month, and its latest occurrences.
     */
    public function show(Team $team, Site $site, SiteError $error): View
    {
        Gate::authorize('view', $site);

        $days = (int) config('ingest.error_occurrence_days');
        $since = now($site->timezone)->subDays($days - 1)->startOfDay();
        $occurrences = $error->occurrences()->where('at', '>=', $since->utc());
        $daily = array_fill_keys(
            array_map(fn (int $n) => $since->addDays($n)->toDateString(), range(0, $days - 1)),
            0,
        );

        foreach ($occurrences->clone()->pluck('at') as $at) {
            $day = $at->setTimezone($site->timezone)->toDateString();

            if (isset($daily[$day])) {
                $daily[$day]++;
            }
        }

        $top = fn (string $column) => $occurrences->clone()->whereNotNull($column)
            ->groupBy($column)->selectRaw("{$column} as label, count(*) as count")
            ->orderByDesc('count')->limit(50)->toBase()->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->count])->all();

        return view('sites.site.error', [
            'team' => $team,
            'site' => $site,
            'error' => $error,
            'daily' => $daily,
            'total' => array_sum($daily),
            'pages' => $top('path'),
            'referrers' => $top('referrer'),
            'latest' => $error->occurrences()->orderByDesc('at')->limit(50)->get(),
            'canChange' => Gate::allows('update', $site),
        ]);
    }

    /**
     * Resolve, ignore or open an error again.
     */
    public function update(Request $request, Team $team, Site $site, SiteError $error): RedirectResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate(['status' => ['required', Rule::enum(ErrorStatus::class)]]);
        $status = ErrorStatus::from($validated['status']);

        $error->update([
            'status' => $status,
            'resolved_at' => $status === ErrorStatus::Resolved ? now() : null,
            'regressed_at' => $status === ErrorStatus::Open ? $error->regressed_at : null,
        ]);

        return back()->with('status', match ($status) {
            ErrorStatus::Resolved => __('Resolved. It opens again if it comes back.'),
            ErrorStatus::Ignored => __('Ignored. Its occurrences are still counted.'),
            ErrorStatus::Open => __('Open again.'),
        });
    }
}
