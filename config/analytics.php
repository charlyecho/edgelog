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

];
