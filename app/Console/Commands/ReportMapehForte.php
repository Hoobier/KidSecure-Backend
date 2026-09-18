<?php
// app/Console/Commands/ReportMapehForte.php
namespace App\Console\Commands;

use App\Models\Teacher;
use Illuminate\Console\Command;

class ReportMapehForte extends Command
{
    protected $signature = 'teachers:report-mapeh-forte';
    protected $description = 'List every teacher whose forteSubjectCode is MAPEH, so the admin can manually reassign them to MA / PE / H.';

    public function handle(): int
    {
        $teachers = Teacher::where('forteSubjectCode', 'MAPEH')->get();

        if ($teachers->isEmpty()) {
            $this->info('No teachers with MAPEH as their forte. Nothing to reassign.');
            return self::SUCCESS;
        }

        $this->warn("Found {$teachers->count()} teacher(s) with forte = MAPEH. Reassign each to MA, PE, or H via the admin teacher edit page.");
        $this->newLine();
        $this->table(
            ['Name', 'Email', 'Home Assignments', 'Visiting Assignments'],
            $teachers->map(function ($t) {
                return [
                    trim("{$t->firstName} {$t->lastName}"),
                    $t->email,
                    count($t->homeAssignments ?? []),
                    count($t->visitingAssignments ?? []),
                ];
            })
        );

        return self::SUCCESS;
    }
}