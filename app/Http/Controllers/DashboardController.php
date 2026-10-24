<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Send the user to the last team they used.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam ?? $user->teams()->first();

        if ($team === null) {
            return redirect()->route('teams.create');
        }

        return redirect()->route('teams.show', $team);
    }
}
