<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fallback for environments without a long-running `outbox:relay --loop` daemon.
Schedule::command('outbox:relay')->everySecond()->withoutOverlapping(1);

Schedule::command('outbox:prune')->daily()->onOneServer();
