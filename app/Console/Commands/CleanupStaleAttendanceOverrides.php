<?php
// app/Console/Commands/CleanupStaleAttendanceOverrides.php
namespace App\Console\Commands;

use App\Models\Student;
use App\Models\TermSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupStaleAttendanceOverrides extends Command
{
    protected $signature = 'students:cleanup-stale-attendance
                            {--dry-run : Report changes without saving}';

    protected $description = 'Delete future/unconfigured attendance overrides and clamp past ones that exceed school days.';

    public function handle(): int
    {
        $setting = TermSetting::current();
        $calendar = $setting->schoolDayCalendar ?? [];
        if (!is_array($calendar)) $calendar = [];
        $monthly = $setting->monthlySchoolDays ?? [];
        if (!is_array($monthly)) $monthly = [];

        $schoolMonths = config('school.school_months', []);
        $today = Carbon::now('Asia/Manila')->startOfDay();

        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $clamped = 0;
        $studentsTouched = 0;

        foreach (Student::all() as $student) {
            $saved = $student->attendanceByMonth ?? [];
            if (!is_array($saved) || empty($saved)) continue;

            $changed = false;

            foreach ($saved as $month => $entry) {
                if (!in_array($month, $schoolMonths, true)) continue;

                $schoolDays = isset($calendar[$month]) && is_array($calendar[$month]) && count($calendar[$month]) > 0
                    ? count($calendar[$month])
                    : ($monthly[$month] ?? null);

                if ($schoolDays === null) {
                    // No configuration → treat as unconfigured, drop the override.
                    unset($saved[$month]);
                    $deleted++;
                    $changed = true;
                    continue;
                }

                // Is this month entirely in the future relative to today?
                $year = $this->yearForMonth($setting, $month);
                if ($year) {
                    $monthStart = Carbon::parse("first day of {$month} {$year}", 'Asia/Manila')->startOfDay();
                    if ($monthStart->greaterThan($today)) {
                        unset($saved[$month]);
                        $deleted++;
                        $changed = true;
                        continue;
                    }
                }

                // Clamp present + tardy to fit school days.
                $p = (int) ($entry['present'] ?? 0);
                $t = (int) ($entry['tardy'] ?? 0);
                if ($p + $t > $schoolDays) {
                    if ($p > $schoolDays) {
                        $p = $schoolDays;
                        $t = 0;
                    } else {
                        $t = $schoolDays - $p;
                    }
                    $saved[$month] = ['present' => $p, 'tardy' => $t];
                    $clamped++;
                    $changed = true;
                    $this->line("Clamp {$student->studentId} {$month}: present={$p} tardy={$t} (schoolDays={$schoolDays})");
                }
            }

            if ($changed) {
                $studentsTouched++;
                if (!$dryRun) {
                    $student->attendanceByMonth = $saved;
                    $student->save();
                }
            }
        }

        $verb = $dryRun ? 'Would have' : 'Did';
        $this->info("{$verb} touch {$studentsTouched} students. Deleted {$deleted} overrides, clamped {$clamped}.");

        return self::SUCCESS;
    }

    private function yearForMonth(TermSetting $setting, string $month): ?int
    {
        $label = $setting->schoolYearLabel ?? null;
        if (!$label || !preg_match('/^(\d{4})-(\d{4})$/', $label, $m)) return null;

        $startYear = (int) $m[1];
        $endYear = (int) $m[2];

        $secondHalf = in_array($month, ['January', 'February', 'March', 'April'], true);
        return $secondHalf ? $endYear : $startYear;
    }
}
