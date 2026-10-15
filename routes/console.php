<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('nodes:check-offline')->everyMinute()->withoutOverlapping();
Schedule::command('metrics:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
