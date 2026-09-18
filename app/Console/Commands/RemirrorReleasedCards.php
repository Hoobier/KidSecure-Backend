<?php
// app/Console/Commands/RemirrorReleasedCards.php
namespace App\Console\Commands;

use App\Models\Student;
use App\Services\FirebaseRealtimeService;
use Illuminate\Console\Command;

class RemirrorReleasedCards extends Command
{
    protected $signature = 'students:remirror-released-cards';
    protected $description = 'Re-push every released report card to Firebase. Run after config changes that alter the subject shape.';

    public function handle(FirebaseRealtimeService $firebase): int
    {
        $students = Student::whereNotNull('reportCardReleasedTerm')
            ->whereIn('enrollmentStatus', ['active', 'inactive'])
            ->get();

        $count = 0;
        foreach ($students as $student) {
            try {
                $firebase->mirrorReportCard($student);
                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed for {$student->studentId}: {$e->getMessage()}");
            }
        }

        $this->info("Re-mirrored {$count} of {$students->count()} released students.");
        return self::SUCCESS;
    }
}