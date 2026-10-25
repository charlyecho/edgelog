<?php

namespace App\Http\Controllers;

use App\Analytics\SitePeriod;
use App\Enums\ConversionAction;
use App\Models\Site;
use App\Models\SiteDayUser;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteUsersController extends Controller
{
    public const int PER_PAGE = 50;

    public const array SORTS = ['pages', 'days', 'last', 'first'];

    /**
     * The users your app named in the log (uid=) and were seen in the period:
     * when each was first seen and where from, the days they came back, the
     * pages they read, and what they paid.
     */
    public function index(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $period = SitePeriod::choose($site, $choice);
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'pages';
        $search = trim((string) $request->query('q'));
        $onlyNew = $request->boolean('new');

        $users = SiteDayUser::query()
            ->join('site_days', 'site_days.id', '=', 'site_day_users.site_day_id')
            ->leftJoin('site_users', fn ($join) => $join->on('site_users.uid', '=', 'site_day_users.uid')->where('site_users.site_id', $site->id))
            ->whereIn('site_day_users.site_day_id', $period->verified()->pluck('id'))
            ->when($search !== '', fn ($query) => $query->whereLike('site_day_users.uid', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%'))
            ->when($onlyNew, fn ($query) => $query->where('site_users.first_seen_at', '>=', $period->first->startOfDay()->utc()))
            ->groupBy('site_day_users.uid', 'site_users.first_seen_at', 'site_users.first_source', 'site_users.first_referrer', 'site_users.first_landing', 'site_users.last_seen_day')
            ->selectRaw('site_day_users.uid, sum(site_day_users.views) as pages, count(*) as days, max(site_days.day) as last_day,
                site_users.first_seen_at, site_users.first_source, site_users.first_referrer, site_users.first_landing, site_users.last_seen_day')
            ->orderByDesc(match ($sort) {
                'days' => 'days', 'last' => 'last_day', 'first' => 'site_users.first_seen_at', default => 'pages'
            })
            ->orderBy('site_day_users.uid')
            ->toBase()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $newUsers = $site->users()->where('first_seen_at', '>=', $period->first->startOfDay()->utc())
            ->where('first_seen_at', '<', $period->last->addDay()->startOfDay()->utc())->count();

        return view('sites.users.index', [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $period,
            'users' => $users,
            'sort' => $sort,
            'search' => $search,
            'onlyNew' => $onlyNew,
            'activeUsers' => $period->signedInUsers(),
            'newUsers' => $newUsers,
            'revenue' => $this->revenue($site, collect($users->items())->pluck('uid')->all()),
        ]);
    }

    /**
     * One user: their first visit, every day they were seen with the pages they read, and their payments.
     */
    public function show(Team $team, Site $site, string $uid): View
    {
        Gate::authorize('view', $site);

        $user = $site->users()->where('uid', $uid)->first();
        $days = SiteDayUser::query()
            ->join('site_days', 'site_days.id', '=', 'site_day_users.site_day_id')
            ->where('site_days.site_id', $site->id)
            ->where('site_day_users.uid', $uid)
            ->orderByDesc('site_days.day')
            ->get(['site_days.day', 'site_day_users.views']);

        abort_if($user === null && $days->isEmpty(), 404);

        return view('sites.users.show', [
            'team' => $team,
            'site' => $site,
            'uid' => $uid,
            'user' => $user,
            'days' => $days,
            'payments' => $site->conversions()->where('uid', $uid)->where('livemode', true)->orderByDesc('occurred_at')->get(),
        ]);
    }

    /**
     * What each of these users paid, refunds taken off, by currency: [uid][currency] => minor units.
     *
     * @param  list<string>  $uids
     * @return array<string, array<string, int>>
     */
    private function revenue(Site $site, array $uids): array
    {
        $revenue = [];

        $site->conversions()->whereIn('uid', $uids)->where('livemode', true)->get(['uid', 'action', 'amount', 'currency'])
            ->each(function ($conversion) use (&$revenue) {
                $amount = $conversion->action === ConversionAction::Paid ? $conversion->amount : -abs($conversion->amount);
                $revenue[$conversion->uid][$conversion->currency] = ($revenue[$conversion->uid][$conversion->currency] ?? 0) + $amount;
            });

        return $revenue;
    }
}
