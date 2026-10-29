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

    // The server's figures (edgelog-ship --metrics): each minute is kept this
    // many days, each hour's averages and peaks this many.
    'metrics_minute_days' => (int) env('INGEST_METRICS_MINUTE_DAYS', 7),
    'metrics_hour_days' => (int) env('INGEST_METRICS_HOUR_DAYS', 400),

    // Application error logs (edgelog-ship --errors): an entry with no line
    // after it is read once its log said nothing for this many seconds; the
    // request of an occurrence is looked for in the access log this many
    // minutes; each occurrence is kept this many days (its error stays).
    'error_tail_seconds' => (int) env('INGEST_ERROR_TAIL_SECONDS', 60),
    'error_lookup_minutes' => (int) env('INGEST_ERROR_LOOKUP_MINUTES', 10),
    'error_occurrence_days' => (int) env('INGEST_ERROR_OCCURRENCE_DAYS', 30),

];
