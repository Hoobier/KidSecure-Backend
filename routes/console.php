<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\EnrollmentApplication;
use App\Models\TermSetting;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('enrollment:cleanup-stale', function () {
    $termStart = TermSetting::current()->currentTermStartDate;

    $deleted = EnrollmentApplication::whereIn('status', ['pending', 'rejected'])
        ->where('created_at', '<', $termStart)
        ->delete();

    $this->info("Deleted {$deleted} stale enrollment application(s) older than {$termStart}.");
})->purpose('Delete pending/rejected enrollment applications older than the current term start date');

Schedule::command('enrollment:cleanup-stale')->daily();