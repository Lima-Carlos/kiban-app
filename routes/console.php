<?php

use Illuminate\Support\Facades\Schedule;

// Every company's license and first steps, every 5 minutes (works offline with the last license).
Schedule::command('kiban:licenses')->everyFiveMinutes()->withoutOverlapping();

// Frota: unfinished fuel codes expire, the warnings of the bell and the removal of old photos and receipts.
Schedule::command('kiban:fleet')->hourlyAt(15)->withoutOverlapping();

// Warnings of the bell older than 90 days are removed.
Schedule::command('kiban:notices-cleanup')->dailyAt('03:30');
