<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The daily run
    |--------------------------------------------------------------------------
    |
    | A site's day is collected once it is over where the site is, plus this
    | many minutes, so lines the shippers send late still make it in.
    |
    */

    'settle_minutes' => (int) env('ANALYTICS_SETTLE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Counting
    |--------------------------------------------------------------------------
    */

    // A browser-like visit that never drew a page (no script, style or
    // framework payload near one of its pages) is a bot.
    'require_rendering' => (bool) env('ANALYTICS_REQUIRE_RENDERING', true),

    // A visit with this many page views or more, all within too_fast_seconds
    // of its first, is a bot. 0 turns the rule off.
    'too_fast_pages' => 6,
    'too_fast_seconds' => 2,

    // A visit ends after this long without a request.
    'visit_gap_minutes' => 30,

    // Days a signed-in user's id is kept: the users of each day, and a user
    // not seen for this long is forgotten.
    'users_kept_days' => (int) env('ANALYTICS_USERS_KEPT_DAYS', 400),

    // Days each anonymous visit (its labels, pages and times) is kept for
    // the list of visits and the visitor flow. The day's counts stay.
    'journey_days' => (int) env('ANALYTICS_JOURNEY_DAYS', 7),

    // Days a reader's keyed hash is kept: a visitor seen on an earlier day
    // within this window is returning.
    'returning_days' => (int) env('ANALYTICS_RETURNING_DAYS', 30),

    // The secret of those hashes. Empty: one drawn from APP_KEY.
    'visitor_key' => env('ANALYTICS_VISITOR_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Where visitors are
    |--------------------------------------------------------------------------
    |
    | MaxMind-format files that tell a visitor's country and city from their
    | address, when the log line does not carry a country of its own. DB-IP's
    | free Lite files (CC BY 4.0) by default, kept up to date each month by
    | edgelog:geoip-update. A missing file: the country or city is not recorded.
    |
    */

    'geoip' => [
        'country' => env('ANALYTICS_COUNTRY_DB', storage_path('app/geoip/dbip-country-lite.mmdb')),
        'city' => env('ANALYTICS_CITY_DB', storage_path('app/geoip/dbip-city-lite.mmdb')),
    ],

];
