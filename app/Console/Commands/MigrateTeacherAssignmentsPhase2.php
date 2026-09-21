<?php
// app/Console/Commands/MigrateTeacherAssignmentsPhase2.php
namespace App\Console\Commands;

use App\Models\Teacher;
use Illuminate\Console\Command;

class MigrateTeacherAssignmentsPhase2 extends Command
{
    protected $signature = 'teachers:migrate-assignments-phase2';
    protected $description = 'Convert each teacher assignment to include a subjects array based on the old forteSubjectCode.';

    public function handle(): int
    {
        // Old-code → new-code mappings for the forte that no longer exists
        // as a valid entry code.
        $expand = [
            'MAPEH' => ['MU', 'AR', 'PE', 'H'],
            'CLVE'  => ['GMRC'],
        ];

        $teachers = Teacher::all();
        $count = 0;

        foreach ($teachers as $teacher) {
            $forte = $teacher->forteSubjectCode ?? null;
            $subjects = [];

            if ($forte) {
                if (isset($expand[$forte])) {
                    $subjects = $expand[$forte];
                } else {
                    $subjects = [$forte];
                }
            }

            $teacher->homeAssignments = collect($teacher->homeAssignments ?? [])->map(function ($a) use ($subjects) {
                return [
                    'gradeLevel' => $a['gradeLevel'] ?? null,
                    'section'    => $a['section'] ?? null,
                    'subjects'   => $a['subjects'] ?? $subjects,
                ];
            })->all();

            $teacher->visitingAssignments = collect($teacher->visitingAssignments ?? [])->map(function ($a) use ($subjects) {
                return [
                    'gradeLevel' => $a['gradeLevel'] ?? null,
                    'section'    => $a['section'] ?? null,
                    'subjects'   => $a['subjects'] ?? $subjects,
                ];
            })->all();

            $teacher->unset('forteSubjectCode');
            $teacher->save();
            $count++;
        }

        $this->info("Migrated {$count} teachers.");
        return self::SUCCESS;
    }
}
