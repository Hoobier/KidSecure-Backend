<?php
// app/Console/Commands/FixLockedTermsStorage.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class FixLockedTermsStorage extends Command
{
    protected $signature = 'students:fix-locked-terms-storage';
    protected $description = 'One-time: convert reportCardLockedTerms from JSON-string storage to native BSON arrays.';

    public function handle(): int
    {
        $students = Student::all();
        $fixed = 0;
        $already = 0;
        $skipped = 0;

        foreach ($students as $student) {
            $raw = $student->getRawOriginal('reportCardLockedTerms');

            // Missing value: nothing to do.
            if ($raw === null) {
                $skipped++;
                continue;
            }

            // Already native array in Mongo: the driver returns a PHP array
            // directly, not a string.
            if (is_array($raw)) {
                $already++;
                continue;
            }

            // The bug: value is a JSON string. Decode it.
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $this->warn("Student {$student->studentId}: could not decode '" . $raw . "'. Skipping.");
                    $skipped++;
                    continue;
                }

                // Reassign as a plain PHP array. Without the cast, the driver
                // will store it as a native BSON array.
                $student->reportCardLockedTerms = array_values($decoded);
                $student->save();
                $fixed++;
                continue;
            }

            // Unexpected type.
            $this->warn("Student {$student->studentId}: unexpected type " . gettype($raw) . ". Skipping.");
            $skipped++;
        }

        $this->info("Fixed: {$fixed}. Already native: {$already}. Skipped: {$skipped}. Total: {$students->count()}.");
        return self::SUCCESS;
    }
}