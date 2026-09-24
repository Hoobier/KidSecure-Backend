<?php
// app/Services/AttendanceDeriveService.php
namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\TermSetting;
use Carbon\Carbon;

class AttendanceDeriveService
{
    public function deriveForStudent(Student $student): array
    {
        $setting = TermSetting::current();
        $schoolYearLabel = $setting->schoolYearLabel ?? null;
        $cutoff = $setting->tardyCutoff ?? '08:00';

        if (!$schoolYearLabel || !preg_match('/^(\d{4})-\d{4}$/', $schoolYearLabel, $m)) {
            return [];
        }

        $startYear = (int) $m[1];
        $nextYear = $startYear + 1;

        $monthToYear = [
            'June'      => $startYear,
            'July'      => $startYear,
            'August'    => $startYear,
            'September' => $startYear,
            'October'   => $startYear,
            'November'  => $startYear,
            'December'  => $startYear,
            'January'   => $nextYear,
            'February'  => $nextYear,
            'March'     => $nextYear,
            'April'     => $nextYear,
        ];

        $out = [];

        foreach (config('school.school_months', []) as $monthName) {
            $year = $monthToYear[$monthName] ?? null;
            if (!$year) continue;

            $hasSchoolLogs = $this->schoolHasLogsForMonth($year, $monthName);
            if (!$hasSchoolLogs) continue;

            $out[$monthName] = $this->studentCountsForMonth($student, $year, $monthName, $cutoff);
        }

        return $out;
    }

    private function schoolHasLogsForMonth(int $year, string $monthName): bool
    {
        [$startUtc, $endUtc] = $this->manilaMonthRange($year, $monthName);
        return AttendanceLog::whereBetween('timestamp', [$startUtc, $endUtc])->exists();
    }

    private function studentCountsForMonth(Student $student, int $year, string $monthName, string $cutoff): array
    {
        [$startUtc, $endUtc] = $this->manilaMonthRange($year, $monthName);

        $logs = AttendanceLog::where('studentId', $student->studentId)
            ->whereBetween('timestamp', [$startUtc, $endUtc])
            ->orderBy('timestamp', 'asc')
            ->get(['timestamp']);

        $earliestPerDay = [];
        foreach ($logs as $log) {
            $ts = Carbon::parse($log->timestamp)->setTimezone('Asia/Manila');
            $dateKey = $ts->toDateString();
            if (!isset($earliestPerDay[$dateKey]) || $ts->lt($earliestPerDay[$dateKey])) {
                $earliestPerDay[$dateKey] = $ts;
            }
        }

        $present = 0;
        $tardy = 0;

        [$cutoffHour, $cutoffMinute] = array_map('intval', explode(':', $cutoff . ':0'));

        foreach ($earliestPerDay as $ts) {
            $isLate = ($ts->hour > $cutoffHour)
                || ($ts->hour === $cutoffHour && $ts->minute > $cutoffMinute);

            if ($isLate) {
                $tardy++;
            } else {
                $present++;
            }
        }

        return ['present' => $present, 'tardy' => $tardy];
    }

    private function manilaMonthRange(int $year, string $monthName): array
    {
        $start = Carbon::parse("{$year}-{$monthName}-01", 'Asia/Manila')->startOfMonth()->setTimezone('UTC');
        $end   = Carbon::parse("{$year}-{$monthName}-01", 'Asia/Manila')->endOfMonth()->setTimezone('UTC');
        return [$start, $end];
    }

    public function mergedForStudent(Student $student): array
    {
        $setting = TermSetting::current();
        $schoolDays = $setting->monthlySchoolDays ?? [];
        $derived = $this->deriveForStudent($student);
        $saved = $student->attendanceByMonth ?? [];

        if (!is_array($saved)) $saved = [];

        $out = [];
        foreach (config('school.school_months', []) as $monthName) {
            $savedEntry = $saved[$monthName] ?? null;
            $derivedEntry = $derived[$monthName] ?? null;
            $entry = $savedEntry ?? $derivedEntry;

            if ($entry === null) {
                $out[$monthName] = [
                    'schoolDays' => $schoolDays[$monthName] ?? null,
                    'present'    => null,
                    'tardy'      => null,
                ];
                continue;
            }

            $out[$monthName] = [
                'schoolDays' => $schoolDays[$monthName] ?? null,
                'present'    => $entry['present'] ?? null,
                'tardy'      => $entry['tardy'] ?? null,
            ];
        }

        return $out;
    }
}