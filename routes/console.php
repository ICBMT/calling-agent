<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Required production safety net; run the Laravel scheduler every minute.
Schedule::command('voice:reap')->everyMinute()->withoutOverlapping(10);
