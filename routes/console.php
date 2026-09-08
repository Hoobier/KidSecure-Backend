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
    $schoolYearStart = TermSetting::current()->schoolYearStartDate();

    if (!$schoolYearStart) {
        $this->info('No school year start date set yet — skipping cleanup.');
        return;
    }

    $deleted = EnrollmentApplication::whereIn('status', ['pending', 'rejected'])
        ->where('created_at', '<', $schoolYearStart)
        ->delete();

    $this->info("Deleted {$deleted} stale enrollment application(s) older than {$schoolYearStart->format('Y-m-d')}.");
})->purpose('Delete pending/rejected enrollment applications older than the current school year start date');

Schedule::command('enrollment:cleanup-stale')->daily();