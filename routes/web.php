<?php

use App\Http\Controllers\AcceptTeamInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ShipperDownloadController;
use App\Http\Controllers\SiteAiController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteErrorsController;
use App\Http\Controllers\SiteHealthController;
use App\Http\Controllers\SiteIconController;
use App\Http\Controllers\SiteInsightsController;
use App\Http\Controllers\SiteIntegrationController;
use App\Http\Controllers\SitePathsController;
use App\Http\Controllers\SiteRealtimeController;
use App\Http\Controllers\SiteSecurityController;
use App\Http\Controllers\SiteServerController;
use App\Http\Controllers\SiteTeamController;
use App\Http\Controllers\SiteTokenController;
use App\Http\Controllers\SiteTrafficController;
use App\Http\Controllers\SiteUsersController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamIconController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberAccessController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Middleware\RememberCurrentTeam;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/downloads/edgelog-ship', ShipperDownloadController::class)->name('shipper.download');

Route::get('/invitations/{token}', [AcceptTeamInvitationController::class, 'show'])->name('invitations.show');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/user/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/user/two-factor', [ProfileController::class, 'twoFactor'])->middleware('password.confirm')->name('profile.two-factor');

    Route::post('/invitations/{token}', [AcceptTeamInvitationController::class, 'store'])->name('invitations.accept');

    Route::get('/teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(RememberCurrentTeam::class)->group(function () {
        Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
        Route::get('/teams/{team}/settings', [TeamController::class, 'edit'])->name('teams.edit');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::get('/teams/{team}/icon', [TeamIconController::class, 'show'])->name('teams.icon');
        Route::post('/teams/{team}/icon', [TeamIconController::class, 'store'])->name('teams.icon.store');
        Route::delete('/teams/{team}/icon', [TeamIconController::class, 'destroy'])->name('teams.icon.destroy');

        Route::put('/teams/{team}/members/{member}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
        Route::get('/teams/{team}/members/{member}/access', [TeamMemberAccessController::class, 'edit'])->name('teams.members.access.edit');
        Route::put('/teams/{team}/members/{member}/access', [TeamMemberAccessController::class, 'update'])->name('teams.members.access.update');

        Route::scopeBindings()->group(function () {
            Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
            Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

            Route::get('/teams/{team}/servers', [ServerController::class, 'index'])->name('teams.servers.index');
            Route::get('/teams/{team}/servers/{server}', [ServerController::class, 'show'])->name('teams.servers.show');
            Route::put('/teams/{team}/servers/{server}', [ServerController::class, 'update'])->name('teams.servers.update');
            Route::delete('/teams/{team}/servers/{server}', [ServerController::class, 'destroy'])->name('teams.servers.destroy');

            Route::get('/teams/{team}/sites', [SiteController::class, 'index'])->name('teams.sites.index');
            Route::get('/teams/{team}/sites/create', [SiteController::class, 'create'])->name('teams.sites.create');
            Route::post('/teams/{team}/sites', [SiteController::class, 'store'])->name('teams.sites.store');
            Route::get('/teams/{team}/sites/{site}', [SiteController::class, 'show'])->name('teams.sites.show');
            Route::get('/teams/{team}/sites/{site}/realtime', SiteRealtimeController::class)->name('teams.sites.realtime');
            Route::get('/teams/{team}/sites/{site}/insights', SiteInsightsController::class)->name('teams.sites.insights');
            Route::get('/teams/{team}/sites/{site}/traffic/visitors', [SiteTrafficController::class, 'visitors'])->name('teams.sites.traffic.visitors');
            Route::get('/teams/{team}/sites/{site}/traffic/sources', [SiteTrafficController::class, 'sources'])->name('teams.sites.traffic.sources');
            Route::get('/teams/{team}/sites/{site}/traffic/pages', [SiteTrafficController::class, 'pages'])->name('teams.sites.traffic.pages');
            Route::get('/teams/{team}/sites/{site}/traffic/places', [SiteTrafficController::class, 'places'])->name('teams.sites.traffic.places');
            Route::get('/teams/{team}/sites/{site}/traffic/retention', [SiteTrafficController::class, 'retention'])->name('teams.sites.traffic.retention');
            Route::get('/teams/{team}/sites/{site}/traffic/time', [SiteTrafficController::class, 'time'])->name('teams.sites.traffic.time');
            Route::get('/teams/{team}/sites/{site}/sales/users', [SiteUsersController::class, 'index'])->name('teams.sites.sales.users');
            Route::get('/teams/{team}/sites/{site}/sales/users/{uid}', [SiteUsersController::class, 'show'])->where('uid', '[A-Za-z0-9_.:-]{1,128}')->name('teams.sites.sales.users.show');
            Route::get('/teams/{team}/sites/{site}/ai/assistants', [SiteAiController::class, 'assistants'])->name('teams.sites.ai.assistants');
            Route::get('/teams/{team}/sites/{site}/ai/crawlers', [SiteAiController::class, 'crawlers'])->name('teams.sites.ai.crawlers');
            Route::get('/teams/{team}/sites/{site}/ai/crawl', [SiteAiController::class, 'crawl'])->name('teams.sites.ai.crawl');
            Route::post('/teams/{team}/sites/{site}/ai/crawl/sitemap', [SiteAiController::class, 'refreshSitemap'])->name('teams.sites.ai.sitemap.refresh');
            Route::get('/teams/{team}/sites/{site}/ai/rules', [SiteAiController::class, 'rules'])->name('teams.sites.ai.rules');
            Route::post('/teams/{team}/sites/{site}/ai/rules/robots', [SiteAiController::class, 'refreshRobots'])->name('teams.sites.ai.robots.refresh');
            Route::get('/teams/{team}/sites/{site}/ai/llms', [SiteAiController::class, 'llms'])->name('teams.sites.ai.llms');
            Route::get('/teams/{team}/sites/{site}/ai/llms/download', [SiteAiController::class, 'downloadLlms'])->name('teams.sites.ai.llms.download');
            Route::get('/teams/{team}/sites/{site}/security/exposed', [SiteSecurityController::class, 'exposed'])->name('teams.sites.security.exposed');
            Route::get('/teams/{team}/sites/{site}/security/ip', [SiteSecurityController::class, 'address'])->name('teams.sites.security.ip');
            Route::get('/teams/{team}/sites/{site}/security/stack', [SiteSecurityController::class, 'stack'])->name('teams.sites.security.stack');
            Route::post('/teams/{team}/sites/{site}/security/stack', [SiteSecurityController::class, 'refreshStack'])->name('teams.sites.security.stack.refresh');
            Route::get('/teams/{team}/sites/{site}/security/scanners', [SiteSecurityController::class, 'scanners'])->name('teams.sites.security.scanners');
            Route::get('/teams/{team}/sites/{site}/security/attacks', [SiteSecurityController::class, 'attacks'])->name('teams.sites.security.attacks');
            Route::get('/teams/{team}/sites/{site}/security/fake', [SiteSecurityController::class, 'fake'])->name('teams.sites.security.fake');
            Route::get('/teams/{team}/sites/{site}/security/block', [SiteSecurityController::class, 'block'])->name('teams.sites.security.block');
            Route::get('/teams/{team}/sites/{site}/security/block/{format}', [SiteSecurityController::class, 'export'])->name('teams.sites.security.block.export');
            Route::get('/teams/{team}/sites/{site}/site/health', [SiteHealthController::class, 'health'])->name('teams.sites.site.health');
            Route::get('/teams/{team}/sites/{site}/site/requests', [SiteHealthController::class, 'requests'])->name('teams.sites.site.requests');
            Route::get('/teams/{team}/sites/{site}/site/statuses', [SiteHealthController::class, 'statuses'])->name('teams.sites.site.statuses');
            Route::get('/teams/{team}/sites/{site}/site/not-found', [SiteHealthController::class, 'notFound'])->name('teams.sites.site.not-found');
            Route::get('/teams/{team}/sites/{site}/site/speed', [SiteHealthController::class, 'speed'])->name('teams.sites.site.speed');
            Route::get('/teams/{team}/sites/{site}/site/quality', [SiteHealthController::class, 'quality'])->name('teams.sites.site.quality');
            Route::get('/teams/{team}/sites/{site}/site/server', [SiteServerController::class, 'show'])->name('teams.sites.site.server');
            Route::put('/teams/{team}/sites/{site}/server', [SiteServerController::class, 'update'])->name('teams.sites.server.update');
            Route::get('/teams/{team}/sites/{site}/site/errors', [SiteErrorsController::class, 'index'])->name('teams.sites.site.errors');
            Route::get('/teams/{team}/sites/{site}/site/errors/{error}', [SiteErrorsController::class, 'show'])->name('teams.sites.site.errors.show');
            Route::put('/teams/{team}/sites/{site}/site/errors/{error}', [SiteErrorsController::class, 'update'])->name('teams.sites.site.errors.update');
            Route::get('/teams/{team}/sites/{site}/settings', [SiteController::class, 'edit'])->name('teams.sites.edit');
            Route::put('/teams/{team}/sites/{site}', [SiteController::class, 'update'])->name('teams.sites.update');
            Route::delete('/teams/{team}/sites/{site}', [SiteController::class, 'destroy'])->name('teams.sites.destroy');
            Route::put('/teams/{team}/sites/{site}/integrations/stripe', [SiteIntegrationController::class, 'update'])->name('teams.sites.stripe.update');
            Route::delete('/teams/{team}/sites/{site}/integrations/stripe', [SiteIntegrationController::class, 'destroy'])->name('teams.sites.stripe.destroy');
            Route::get('/teams/{team}/sites/{site}/icon', [SiteIconController::class, 'show'])->name('teams.sites.icon');
            Route::post('/teams/{team}/sites/{site}/icon', [SiteIconController::class, 'store'])->name('teams.sites.icon.store');
            Route::post('/teams/{team}/sites/{site}/icon/fetch', [SiteIconController::class, 'fetch'])->name('teams.sites.icon.fetch');
            Route::delete('/teams/{team}/sites/{site}/icon', [SiteIconController::class, 'destroy'])->name('teams.sites.icon.destroy');
            Route::put('/teams/{team}/sites/{site}/paths', [SitePathsController::class, 'update'])->name('teams.sites.paths.update');
            Route::post('/teams/{team}/sites/{site}/token', [SiteTokenController::class, 'store'])->name('teams.sites.token.store');
            Route::put('/teams/{team}/sites/{site}/team', [SiteTeamController::class, 'update'])->name('teams.sites.team.update');
        });
    });
});
