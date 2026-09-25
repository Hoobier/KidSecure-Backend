<?php
// app/Console/Commands/BackfillPreschoolAssignmentSubjects.php
namespace App\Console\Commands;

use App\Models\Teacher;
use App\Services\GradeSubjectService;
use Illuminate\Console\Command;

class BackfillPreschoolAssignmentSubjects extends Command
{
    protected $signature = 'teachers:backfill-preschool-subjects';
    protected $description = 'One-time: fill empty subjects arrays on preschool teachers\' home and visiting assignments with the grade-level entry codes.';

    public function handle(): int
    {
        $teachers = Teacher::where('department', 'preschool')->get();
        $updated = 0;
        $skipped = 0;

        foreach ($teachers as $teacher) {
            $changed = false;

            foreach (['homeAssignments', 'visitingAssignments'] as $field) {
                $rows = $teacher->{$field} ?? [];
                if (!is_array($rows)) $rows = [];

                $newRows = [];
                foreach ($rows as $row) {
                    $grade    = $row['gradeLevel'] ?? null;
                    $section  = $row['section'] ?? null;
                    $subjects = $row['subjects'] ?? [];

                    // If subjects already populated, leave as-is.
                    if (!empty($subjects)) {
                        $newRows[] = [
                            'gradeLevel' => $grade,
                            'section'    => $section,
                            'subjects'   => array_values($subjects),
                        ];
                        continue;
                    }

                    // Empty subjects — fill with entry codes for the grade.
                    if (!$grade || !$section) {
                        continue;
                    }

                    $filled = GradeSubjectService::entryCodesForGrade($grade);
                    if (empty($filled)) {
                        $this->warn("No entry codes configured for {$grade}; skipping {$teacher->email}.");
                        continue;
                    }

                    $newRows[] = [
                        'gradeLevel' => $grade,
                        'section'    => $section,
                        'subjects'   => $filled,
                    ];
                    $changed = true;
                }

                $teacher->{$field} = $newRows;
            }

            if ($changed) {
                $teacher->save();
                $updated++;
                $this->line("Updated: {$teacher->email}");
            } else {
                $skipped++;
            }
        }

        $this->info("Done. Updated: {$updated}. Skipped (already had subjects): {$skipped}. Total preschool teachers: {$teachers->count()}.");
        return self::SUCCESS;
    }
}
