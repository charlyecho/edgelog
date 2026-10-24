<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Show the user's profile and password settings.
     */
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }

    /**
     * Show the user's two-step sign-in settings.
     */
    public function twoFactor(Request $request): View
    {
        return view('profile.two-factor', ['user' => $request->user()]);
    }
}
