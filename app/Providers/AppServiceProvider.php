<?php

namespace App\Providers;

use App\Analytics\Geo\MmdbPlaces;
use App\Analytics\Geo\Places;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Middleware\TrustProxies;
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

            $team = $routeTeam instanceof Team && $user->belongsToTeam($routeTeam) ? $routeTeam : $user->currentTeam;

            $view->with([
                'railTeam' => $team,
                'railSite' => $routeSite instanceof Site ? $routeSite : null,
                'railSites' => $team?->sites()->orderBy('name')->get() ?? collect(),
                'railTeams' => $user->teams,
            ]);
        });
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
