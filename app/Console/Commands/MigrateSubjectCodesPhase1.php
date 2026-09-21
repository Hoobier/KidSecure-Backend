<?php
// app/Console/Commands/MigrateSubjectCodesPhase1.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateSubjectCodesPhase1 extends Command
{
    protected $signature = 'students:migrate-subject-codes';
    protected $description = 'Migrate report card data to the new subject codes: CLVE→GMRC, MAPEH→MU/AR/PE/H.';

    public function handle(): int
    {
        $students = Student::all();
        $clveToGmrc = 0;
        $mapehToComponents = 0;

        foreach ($students as $student) {
            $card = $student->reportCard ?? [];
            $changed = false;

            // CLVE → GMRC
            if (isset($card['CLVE'])) {
                if (!isset($card['GMRC'])) {
                    $card['GMRC'] = $card['CLVE'];
                }
                unset($card['CLVE']);
                $clveToGmrc++;
                $changed = true;
            }

            // MAPEH → MU / AR / PE / H (copy the same grade/status to each)
            if (isset($card['MAPEH'])) {
                foreach (['MU', 'AR', 'PE', 'H'] as $code) {
                    if (!isset($card[$code])) {
                        $card[$code] = $card['MAPEH'];
                    }
                }
                unset($card['MAPEH']);
                $mapehToComponents++;
                $changed = true;
            }

            if ($changed) {
                $student->reportCard = $card;
                $student->save();
            }
        }

        $this->info("Done. CLVE→GMRC: {$clveToGmrc}. MAPEH→components: {$mapehToComponents}.");
        return self::SUCCESS;
    }
}