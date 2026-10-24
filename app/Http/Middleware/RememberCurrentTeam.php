<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberCurrentTeam
{
    /**
     * Make the team in the URL the user's current team, so the dashboard
     * brings them back to the last team they used.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');
        $user = $request->user();

        if ($team instanceof Team && $user !== null && $user->current_team_id !== $team->getKey() && $user->belongsToTeam($team)) {
            $user->switchTeam($team);
        }

        return $next($request);
    }
}
