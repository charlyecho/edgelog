<?php

namespace App\Providers;

use App\Analytics\Geo\MmdbPlaces;
use App\Analytics\Geo\Places;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Places::class, fn () => MmdbPlaces::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustProxies();

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        View::composer('components.layouts.app', function (ViewContract $view) {
            $user = request()->user();
            $routeTeam = request()->route('team');
            $routeSite = request()->route('site');

            $routeServer = request()->route('server');

            $team = $routeTeam instanceof Team && $user->belongsToTeam($routeTeam) ? $routeTeam : $user->currentTeam;
            $sites = $team?->sitesVisibleTo($user)->orderBy('name')->get() ?? collect();

            $view->with([
                'railTeam' => $team,
                'railSite' => $routeSite instanceof Site ? $routeSite : null,
                'railServer' => $routeServer instanceof Server ? $routeServer : null,
                'railSites' => $sites,
                'railGroups' => $team === null ? [] : $this->railGroups($team->serversVisibleTo($user)->with('sites')->get(), $sites),
                'railTeams' => $user->teams,
            ]);
        });
    }

    /**
     * The sites of the switcher, under the server each runs on, servers by
     * name; then those whose server is unknown, or not granted to the user.
     *
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, Site>  $sites
     * @return list<array{server: ?Server, sites: Collection<int, Site>}>
     */
    private function railGroups(Collection $servers, Collection $sites): array
    {
        $groups = $servers
            ->sortBy(fn (Server $server) => mb_strtolower($server->name()))
            ->map(fn (Server $server) => ['server' => $server, 'sites' => $sites->where('server_id', $server->id)->values()])
            ->values()
            ->all();
        $elsewhere = $sites->reject(fn (Site $site) => $servers->contains('id', $site->server_id))->values();

        return $elsewhere->isEmpty() ? $groups : [...$groups, ['server' => null, 'sites' => $elsewhere]];
    }

    /**
     * Believe the X-Forwarded-* headers of the proxies set in TRUSTED_PROXIES
     * (config app.trusted_proxies), so the login throttle and every address
     * read from a request is the visitor's, not the proxy's.
     */
    private function trustProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies'));

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }
    }
}
