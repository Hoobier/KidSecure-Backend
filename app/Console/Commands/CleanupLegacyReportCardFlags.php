<?php
// app/Console/Commands/CleanupLegacyReportCardFlags.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class CleanupLegacyReportCardFlags extends Command
{
    protected $signature = 'students:cleanup-legacy-flags';
    protected $description = 'One-time: unset reportCardSubmittedToAdmin / reportCardReleased / reportCardAdminLocked from every student. Run ONLY after Phase 6 code is deployed and verified.';

    public function handle(): int
    {
        $fields = [
            'reportCardSubmittedToAdmin',
            'reportCardReleased',
            'reportCardAdminLocked',
        ];

        $students = Student::all();
        $updated = 0;

        foreach ($students as $student) {
            $changed = false;
            foreach ($fields as $field) {
                if (isset($student->{$field})) {
                    $student->unset($field);
                    $changed = true;
                }
            }
            if ($changed) {
                $student->save();
                $updated++;
            }
        }

        $this->info("Done. Removed legacy flags from {$updated} of {$students->count()} students.");
        return self::SUCCESS;
    }
}