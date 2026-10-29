<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('edgelog:run-daily')->hourly()->withoutOverlapping();
Schedule::command('edgelog:count-history')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('edgelog:live')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('edgelog:errors')->everyMinute()->withoutOverlapping();
Schedule::command('edgelog:errors --prune')->dailyAt('03:40');
Schedule::command('edgelog:roll-metrics')->hourlyAt(2)->withoutOverlapping();
Schedule::command('edgelog:geoip-update')->monthlyOn(3, '04:00');
