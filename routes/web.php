<?php

use App\Http\Controllers\AcceptTeamInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShipperDownloadController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteIntegrationController;
use App\Http\Controllers\SiteRealtimeController;
use App\Http\Controllers\SiteTeamController;
use App\Http\Controllers\SiteTokenController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
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

        Route::put('/teams/{team}/members/{member}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::scopeBindings()->group(function () {
            Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
            Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

            Route::get('/teams/{team}/sites', [SiteController::class, 'index'])->name('teams.sites.index');
            Route::get('/teams/{team}/sites/create', [SiteController::class, 'create'])->name('teams.sites.create');
            Route::post('/teams/{team}/sites', [SiteController::class, 'store'])->name('teams.sites.store');
            Route::get('/teams/{team}/sites/{site}', [SiteController::class, 'show'])->name('teams.sites.show');
            Route::get('/teams/{team}/sites/{site}/realtime', SiteRealtimeController::class)->name('teams.sites.realtime');
            Route::get('/teams/{team}/sites/{site}/settings', [SiteController::class, 'edit'])->name('teams.sites.edit');
            Route::put('/teams/{team}/sites/{site}', [SiteController::class, 'update'])->name('teams.sites.update');
            Route::delete('/teams/{team}/sites/{site}', [SiteController::class, 'destroy'])->name('teams.sites.destroy');
            Route::put('/teams/{team}/sites/{site}/integrations/stripe', [SiteIntegrationController::class, 'update'])->name('teams.sites.stripe.update');
            Route::delete('/teams/{team}/sites/{site}/integrations/stripe', [SiteIntegrationController::class, 'destroy'])->name('teams.sites.stripe.destroy');
            Route::post('/teams/{team}/sites/{site}/token', [SiteTokenController::class, 'store'])->name('teams.sites.token.store');
            Route::put('/teams/{team}/sites/{site}/team', [SiteTeamController::class, 'update'])->name('teams.sites.team.update');
        });
    });
});
