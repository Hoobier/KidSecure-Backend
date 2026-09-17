<?php
// app/Console/Commands/MigrateTeacherAssignments.php
namespace App\Console\Commands;

use App\Models\Teacher;
use Illuminate\Console\Command;

class MigrateTeacherAssignments extends Command
{
    protected $signature = 'teachers:migrate-assignments';
    protected $description = 'One-time: remove homeGradeLevel, initialize homeAssignments / visitingAssignments / status on all teachers.';

    public function handle(): int
    {
        $teachers = Teacher::all();
        $updated = 0;
        $already = 0;

        foreach ($teachers as $teacher) {
            $needsWork =
                isset($teacher->homeGradeLevel) ||
                !isset($teacher->homeAssignments) ||
                !isset($teacher->visitingAssignments) ||
                !isset($teacher->status);

            if (!$needsWork) {
                $already++;
                $this->line("  · {$teacher->firstName} {$teacher->lastName} — already migrated");
                continue;
            }

            $payload = [];

            if (isset($teacher->homeGradeLevel)) {
                // Capture for the log but do not preserve on the record.
                $this->line("  · {$teacher->firstName} {$teacher->lastName} — old homeGradeLevel: {$teacher->homeGradeLevel}");
            }

            if (!isset($teacher->homeAssignments)) {
                $payload['homeAssignments'] = [];
            }
            if (!isset($teacher->visitingAssignments)) {
                $payload['visitingAssignments'] = [];
            }
            if (!isset($teacher->status)) {
                $payload['status'] = 'active';
            }

            // Unset the legacy field.
            $teacher->unset('homeGradeLevel');

            foreach ($payload as $k => $v) {
                $teacher->{$k} = $v;
            }

            $teacher->save();
            $updated++;
        }

        $this->info("Done. Updated: {$updated}, already migrated: {$already}, total: " . $teachers->count());
        $this->warn('Next step: open each teacher in /teachers/[id]/edit and assign their home and visiting classes.');

        return self::SUCCESS;
    }
}