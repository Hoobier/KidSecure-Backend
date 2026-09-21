<?php
// app/Console/Commands/MigrateStudentArchivedAt.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateStudentArchivedAt extends Command
{
    protected $signature = 'students:migrate-archived-at';
    protected $description = 'One-time: set archivedAt on students already marked graduated or transferred_out.';

    public function handle(): int
    {
        $students = Student::whereIn('enrollmentStatus', ['graduated', 'transferred_out'])
            ->whereNull('archivedAt')
            ->get();

        $count = 0;
        foreach ($students as $student) {
            $student->archivedAt = $student->updated_at ?? now();
            $student->save();
            $count++;
        }

        $this->info("Set archivedAt on {$count} students.");
        return self::SUCCESS;
    }
}