<?php

use Illuminate\Support\Facades\Schedule;

// Every company's license and first steps, every 5 minutes (works offline with the last license).
Schedule::command('kiban:licenses')->everyFiveMinutes()->withoutOverlapping();
