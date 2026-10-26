<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where shipped logs are kept
    |--------------------------------------------------------------------------
    |
    | One folder per site (its UUID), holding access.log for today (UTC),
    | access.log.1 for the day before and access.log.N.gz further back.
    |
    */

    'root' => env('INGEST_ROOT', storage_path('app/private/ingest')),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    // Batches a minute per site.
    'per_minute' => (int) env('INGEST_PER_MINUTE', 120),

    // Days of rotated files kept per site.
    'keep' => (int) env('INGEST_KEEP', 14),

    // The most one site's file may hold in a day, in MB.
    'max_mb' => (float) env('INGEST_MAX_MB', 1024),

    // The most a team's sites may ship a UTC day, together, in MB.
    'team_daily_mb' => (float) env('INGEST_TEAM_DAILY_MB', 200),

    // Every batch is refused (507) below this much free disk, in GB.
    'min_free_gb' => (float) env('INGEST_MIN_FREE_GB', 5),

    // A batch body, packed and unpacked, in bytes.
    'max_body' => 5 * 1024 * 1024,

    // One log line, in bytes.
    'max_line' => 64 * 1024,

    // Past logs (edgelog-ship --backfill): their days are counted again once
    // no part came for this many minutes.
    'history_settle_minutes' => (int) env('INGEST_HISTORY_SETTLE_MINUTES', 5),

];
