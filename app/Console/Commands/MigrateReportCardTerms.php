<?php
// app/Console/Commands/MigrateReportCardTerms.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateReportCardTerms extends Command
{
    protected $signature = 'students:migrate-report-card-terms';
    protected $description = 'One-time: seed reportCardSubmittedTerm / ReleasedTerm / LockedTerm from the flat flags. Defaults to T1 for any pre-existing true flag.';

    public function handle(): int
    {
        $students = Student::all();
        $updated = 0;
        $already = 0;

        foreach ($students as $student) {
            $needsWork =
                !isset($student->reportCardSubmittedTerm) ||
                !isset($student->reportCardReleasedTerm) ||
                !isset($student->reportCardLockedTerm);

            if (!$needsWork) {
                $already++;
                continue;
            }

            // Every pre-migration flag means "this happened in T1" — that's
            // the only term anyone has been using since Spec 2 shipped.
            $defaultTerm = 'T1';

            if (!isset($student->reportCardSubmittedTerm)) {
                $student->reportCardSubmittedTerm =
                    ($student->reportCardSubmittedToAdmin ?? false) ? $defaultTerm : null;
            }
            if (!isset($student->reportCardReleasedTerm)) {
                $student->reportCardReleasedTerm =
                    ($student->reportCardReleased ?? false) ? $defaultTerm : null;
            }
            if (!isset($student->reportCardLockedTerm)) {
                $student->reportCardLockedTerm =
                    ($student->reportCardAdminLocked ?? false) ? $defaultTerm : null;
            }

            $student->save();
            $updated++;
        }

        $this->info("Done. Updated: {$updated}, already migrated: {$already}, total: {$students->count()}");
        return self::SUCCESS;
    }
}