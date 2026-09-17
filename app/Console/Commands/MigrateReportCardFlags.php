<?php
// app/Console/Commands/MigrateReportCardFlags.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateReportCardFlags extends Command
{
    protected $signature = 'students:migrate-report-card-flags';
    protected $description = 'One-time: initialize reportCardSubmittedToAdmin / reportCardSubmittedAt / reportCardAdminLocked on every student.';

    public function handle(): int
    {
        $students = Student::all();
        $updated = 0;
        $already = 0;

        foreach ($students as $student) {
            $needsWork =
                !isset($student->reportCardSubmittedToAdmin) ||
                !isset($student->reportCardAdminLocked) ||
                (!isset($student->reportCardSubmittedAt) && $student->reportCardSubmittedToAdmin ?? false);

            if (!$needsWork) {
                $already++;
                continue;
            }

            $payload = [];
            if (!isset($student->reportCardSubmittedToAdmin)) {
                $payload['reportCardSubmittedToAdmin'] = false;
            }
            if (!isset($student->reportCardAdminLocked)) {
                $payload['reportCardAdminLocked'] = false;
            }
            if (!isset($student->reportCardSubmittedAt)) {
                $payload['reportCardSubmittedAt'] = null;
            }

            foreach ($payload as $k => $v) {
                $student->{$k} = $v;
            }
            $student->save();
            $updated++;
        }

        $this->info("Done. Updated: {$updated}, already migrated: {$already}, total: {$students->count()}");
        return self::SUCCESS;
    }
}