<?php
// app/Console/Commands/MigrateLockedTerms.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateLockedTerms extends Command
{
    protected $signature = 'students:migrate-locked-terms';
    protected $description = 'One-time: convert reportCardLockedTerm (string) to reportCardLockedTerms (array).';

    public function handle(): int
    {
        $students = Student::all();
        $converted = 0;
        $skipped = 0;

        foreach ($students as $student) {
            $legacy = $student->reportCardLockedTerm ?? null;

            // Already migrated (array exists, no legacy string).
            if (!isset($student->reportCardLockedTerm) && isset($student->reportCardLockedTerms)) {
                $skipped++;
                continue;
            }

            $terms = $student->reportCardLockedTerms ?? [];
            if (!is_array($terms)) $terms = [];

            if ($legacy && !in_array($legacy, $terms, true)) {
                $terms[] = $legacy;
            }

            $student->reportCardLockedTerms = array_values(array_unique($terms));
            $student->unset('reportCardLockedTerm');
            $student->save();
            $converted++;
        }

        $this->info("Converted: {$converted}. Skipped (already migrated): {$skipped}.");
        return self::SUCCESS;
    }
}