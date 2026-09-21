<?php
// app/Console/Commands/MigrateParentArchivedAt.php
namespace App\Console\Commands;

use App\Models\ParentAccount;
use App\Models\Student;
use Illuminate\Console\Command;

class MigrateParentArchivedAt extends Command
{
    protected $signature = 'parents:migrate-archived-at';
    protected $description = 'One-time: set archivedAt on parents whose linked students are all archived or deleted.';


    public function handle(): int
    {
        $parents = ParentAccount::whereNull('archivedAt')->get();
        $count = 0;

        foreach ($parents as $parent) {
            $linked = Student::whereIn('_id', $parent->studentIds ?? [])->get();

            if ($linked->isEmpty()) {
                // No linked students at all — treat as archived.
                $parent->archivedAt = now();
                $parent->save();
                $count++;
                continue;
            }

            $allInactive = $linked->every(function ($s) {
                return in_array($s->enrollmentStatus, ['graduated', 'transferred_out', 'deleted'], true);
            });

            if ($allInactive) {
                $parent->archivedAt = now();
                $parent->save();
                $count++;
            }
        }

        $this->info("Set archivedAt on {$count} parents.");
        return self::SUCCESS;
    }
}