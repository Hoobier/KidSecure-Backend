<?php
// app/Console/Commands/MigrateMapehToComponents.php
namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MigrateMapehToComponents extends Command
{
    protected $signature = 'students:migrate-mapeh-to-components';
    protected $description = 'One-time: copy each student\'s MAPEH grades into MA / PE / H. Leaves the original MAPEH key intact for archival.';

    public function handle(): int
    {
        $components = ['MA', 'PE', 'H'];
        $students = Student::all();
        $updated = 0;
        $skipped = 0;

        foreach ($students as $student) {
            $card = $student->reportCard ?? [];

            if (empty($card['MAPEH'])) {
                $skipped++;
                continue;
            }

            $changed = false;
            foreach ($components as $code) {
                // Only copy if the component doesn't already exist.
                if (isset($card[$code])) {
                    continue;
                }
                $card[$code] = [];
                foreach ($card['MAPEH'] as $term => $entry) {
                    if (is_array($entry) && array_key_exists('grade', $entry)) {
                        $card[$code][$term] = [
                            'grade'  => $entry['grade'],
                            'status' => $entry['status'] ?? 'compiled',
                        ];
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $student->reportCard = $card;
                $student->save();
                $updated++;
            } else {
                $skipped++;
            }
        }

        $this->info("Done. Migrated: {$updated}, skipped (no MAPEH or already done): {$skipped}, total: {$students->count()}");
        return self::SUCCESS;
    }
}