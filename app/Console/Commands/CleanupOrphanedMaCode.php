<?php
// app/Console/Commands/CleanupOrphanedMaCode.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class CleanupOrphanedMaCode extends Command
{
    protected $signature = 'students:cleanup-ma-code';
    protected $description = 'Migrate leftover "MA" (old combined Music & Arts) into MU and AR, then remove it.';

    public function handle(): int
    {
        $students = Student::all();
        $count = 0;

        foreach ($students as $student) {
            $card = $student->reportCard ?? [];
            if (!isset($card['MA'])) continue;

            // MA was a combined Music & Arts grade. Copy into MU and AR
            // if those don't already exist. Never overwrite real data.
            if (!isset($card['MU'])) $card['MU'] = $card['MA'];
            if (!isset($card['AR'])) $card['AR'] = $card['MA'];

            unset($card['MA']);

            $student->reportCard = $card;
            $student->save();
            $count++;
        }

        $this->info("Cleaned up MA on {$count} students.");
        return self::SUCCESS;
    }
}
