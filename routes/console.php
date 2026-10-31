<?php

use App\Models\AccessLog;
use Illuminate\Support\Facades\Schedule;

// Every company's license and first steps, every 5 minutes (works offline with the last license).
Schedule::command('kiban:licenses')->everyFiveMinutes()->withoutOverlapping();

// Frota: unfinished fuel codes expire, the warnings of the bell and the removal of old photos and receipts.
Schedule::command('kiban:fleet')->hourlyAt(15)->withoutOverlapping();

// Warnings of the bell older than 90 days are removed.
Schedule::command('kiban:notices-cleanup')->dailyAt('03:30');

// Ponto: the warnings of the bell (forgotten punches, month not closed) and the removal of old punch photos.
Schedule::command('kiban:timeclock')->hourlyAt(20)->withoutOverlapping();

// Marco Civil: access records older than the retention period (at least 6 months) are removed.
Schedule::command('model:prune', ['--model' => [AccessLog::class]])->dailyAt('03:45');

// Campo: addresses of new customers searched on the map (one per second, a few per minute).
Schedule::command('kiban:field')->everyMinute()->withoutOverlapping();

// Documents of the people (driver's license, medical exam, trainings): the bell warns before they expire (07:00 in Brasília).
Schedule::command('kiban:documents')->dailyAt('10:00')->withoutOverlapping();

// PPE and tools: replacements, CA, deliveries waiting for the signature and low stock (07:10 in Brasília).
Schedule::command('kiban:epi')->dailyAt('10:10')->withoutOverlapping();
