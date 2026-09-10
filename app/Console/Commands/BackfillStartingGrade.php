<?php

namespace App\Console\Commands;

use App\Models\AcademicRecord;
use App\Models\Student;
use Illuminate\Console\Command;

class BackfillStartingGrade extends Command
{
    protected $signature = 'students:backfill-starting-grade';

    protected $description = 'Backfill starting grade levels for existing students.';

    public function handle(): int
    {
        $updated = 0;

        foreach (Student::all() as $student) {
            if (!empty($student->startingGradeLevel)) {
                continue;
            }

            $earliestRecord = AcademicRecord::where(
                'studentId',
                $student->studentId
            )
                ->orderBy('created_at', 'asc')
                ->first();

            $student->startingGradeLevel = $earliestRecord?->gradeLevel
                ?? $student->gradeLevel;
            $student->save();
            $updated++;
        }

        $this->info("Updated {$updated} student(s).");

        return self::SUCCESS;
    }
}
