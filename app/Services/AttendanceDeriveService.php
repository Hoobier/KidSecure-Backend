<?php
// app/Services/AttendanceDeriveService.php
namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\TermSetting;
use Carbon\Carbon;

class AttendanceDeriveService
{
    /**
     * Returns per-month attendance data for a student, clipped to the view
     * term's end date (or today, whichever is earlier).
     *
     * Each month entry:
     *   - schoolDays       total school days configured for that month
     *   - schoolDaysUpTo   school days that fall on or before the clip end
     *   - present          on-time taps within the clip
     *   - tardy            late taps within the clip
     *   - absent           schoolDaysUpTo − present − tardy (null when partial)
     *   - partial          true when the month straddles the clip end
     *   - source           'auto' | 'overridden' | 'manual' | 'future' | 'unconfigured'
     */
    public function mergedForStudent(Student $student, ?string $viewTerm = null): array
    {
        $setting = TermSetting::current();

        if (!$viewTerm) {
            $n = $setting->activeTermNumber() ?? 1;
            $viewTerm = "T{$n}";
        }

        $startYear = $this->extractStartYear($setting);
        if ($startYear === null) {
            return $this->emptyMonthsShape();
        }
        $nextYear = $startYear + 1;
        $monthToYear = $this->monthYearMap($startYear, $nextYear);

        $clipEnd = $this->clipEndForTerm($setting, $viewTerm);
        $cutoff = $setting->tardyCutoff ?? '08:00';

        $calendar = $setting->schoolDayCalendar ?? [];
        if (!is_array($calendar)) $calendar = [];

        $monthlyCounts = $setting->monthlySchoolDays ?? [];
        if (!is_array($monthlyCounts)) $monthlyCounts = [];

        $saved = $student->attendanceByMonth ?? [];
        if (!is_array($saved)) $saved = [];

        $hasRfid = !empty($student->rfidTag);

        // Fetch all taps for the whole school year up to the clip, once.
        $schoolYearStart = Carbon::parse("{$startYear}-06-01", 'Asia/Manila')->startOfDay();
        $earliestTapByDate = $hasRfid
            ? $this->earliestTapByDate($student, $schoolYearStart, $clipEnd)
            : [];

        $out = [];
        foreach (config('school.school_months', []) as $monthName) {
            $year = $monthToYear[$monthName] ?? null;
            if (!$year) continue;

            $out[$monthName] = $this->computeMonth(
                $monthName,
                $year,
                $clipEnd,
                $calendar[$monthName] ?? [],
                $monthlyCounts[$monthName] ?? null,
                $saved[$monthName] ?? null,
                $earliestTapByDate,
                $cutoff,
                $hasRfid
            );
        }

        return $out;
    }

    private function computeMonth(
        string $monthName,
        int $year,
        Carbon $clipEnd,
        array $calendarDates,
        ?int $monthlyCount,
        ?array $savedEntry,
        array $earliestTapByDate,
        string $cutoff,
        bool $hasRfid
    ): array {
        $monthStart = Carbon::parse("first day of {$monthName} {$year}", 'Asia/Manila')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Entirely in the future relative to the clip.
        if ($monthStart->greaterThan($clipEnd)) {
            return $this->nullMonth('future', false);
        }

        // Determine schoolDays and schoolDaysUpTo.
        $calendarCount = count($calendarDates);
        $hasCalendar = $calendarCount > 0;

        if ($hasCalendar) {
            $schoolDays = $calendarCount;
            $schoolDaysUpTo = 0;
            foreach ($calendarDates as $d) {
                $dDate = Carbon::parse($d, 'Asia/Manila')->startOfDay();
                if ($dDate->lessThanOrEqualTo($clipEnd)) {
                    $schoolDaysUpTo++;
                }
            }
        } elseif ($monthlyCount !== null) {
            // Transitional fallback: use the plain count, no partial support.
            $schoolDays = $monthlyCount;
            $schoolDaysUpTo = $monthlyCount;
        } else {
            return $this->nullMonth('unconfigured', false);
        }

        $partial = $monthEnd->greaterThan($clipEnd);

        // Source precedence.
        if (!$hasRfid) {
            $source = 'manual';
        } elseif ($savedEntry !== null) {
            $source = 'overridden';
        } else {
            $source = 'auto';
        }

        // Compute present/tardy.
        if ($savedEntry !== null) {
            $present = isset($savedEntry['present']) ? (int) $savedEntry['present'] : null;
            $tardy   = isset($savedEntry['tardy'])   ? (int) $savedEntry['tardy']   : null;
        } elseif (!$hasRfid) {
            $present = null;
            $tardy   = null;
        } else {
            [$present, $tardy] = $this->countTapsForMonth(
                $monthStart,
                $monthEnd,
                $clipEnd,
                $calendarDates,
                $earliestTapByDate,
                $cutoff,
                $hasCalendar
            );
        }

        // Absent only computable when the month is complete.
        $absent = null;
        if (!$partial && $present !== null && $tardy !== null) {
            $absent = max(0, $schoolDaysUpTo - $present - $tardy);
        }

        return [
            'schoolDays'     => $schoolDays,
            'schoolDaysUpTo' => $schoolDaysUpTo,
            'present'        => $present,
            'tardy'          => $tardy,
            'absent'         => $absent,
            'partial'        => $partial,
            'source'         => $source,
        ];
    }

    private function countTapsForMonth(
        Carbon $monthStart,
        Carbon $monthEnd,
        Carbon $clipEnd,
        array $calendarDates,
        array $earliestTapByDate,
        string $cutoff,
        bool $hasCalendar
    ): array {
        [$cutoffHour, $cutoffMinute] = $this->parseCutoff($cutoff);
        $present = 0;
        $tardy = 0;

        if ($hasCalendar) {
            // Only school days matter — iterate the calendar.
            foreach ($calendarDates as $d) {
                $dDate = Carbon::parse($d, 'Asia/Manila')->startOfDay();
                if ($dDate->greaterThan($clipEnd)) continue;
                $tap = $earliestTapByDate[$d] ?? null;
                if ($tap === null) continue;
                if ($this->isLate($tap, $cutoffHour, $cutoffMinute)) {
                    $tardy++;
                } else {
                    $present++;
                }
            }
        } else {
            // Fallback: no per-date calendar, count every tap-day in the month.
            foreach ($earliestTapByDate as $dateKey => $tap) {
                $tapDate = Carbon::parse($dateKey, 'Asia/Manila')->startOfDay();
                if ($tapDate->lessThan($monthStart)) continue;
                if ($tapDate->greaterThan($monthEnd)) continue;
                if ($tapDate->greaterThan($clipEnd)) continue;
                if ($this->isLate($tap, $cutoffHour, $cutoffMinute)) {
                    $tardy++;
                } else {
                    $present++;
                }
            }
        }

        return [$present, $tardy];
    }

    /**
     * Fetches all taps for a student within a date range and returns the
     * earliest tap per Manila calendar date, keyed by Y-m-d string.
     */
    private function earliestTapByDate(Student $student, Carbon $from, Carbon $to): array
    {
        $startUtc = $from->copy()->startOfDay()->setTimezone('UTC');
        $endUtc   = $to->copy()->endOfDay()->setTimezone('UTC');

        $logs = AttendanceLog::where('studentId', $student->studentId)
            ->whereBetween('timestamp', [$startUtc, $endUtc])
            ->orderBy('timestamp', 'asc')
            ->get(['timestamp']);

        $out = [];
        foreach ($logs as $log) {
            $ts = Carbon::parse($log->timestamp)->setTimezone('Asia/Manila');
            $dateKey = $ts->toDateString();
            if (!isset($out[$dateKey]) || $ts->lt($out[$dateKey])) {
                $out[$dateKey] = $ts;
            }
        }

        return $out;
    }

    private function clipEndForTerm(TermSetting $setting, string $viewTerm): Carbon
    {
        $termNumber = (int) substr($viewTerm, 1);
        $term = collect($setting->terms)->firstWhere('termNumber', $termNumber);

        $endDate = $term['endDate'] ?? null;
        if (!$endDate) {
            return Carbon::now('Asia/Manila')->endOfDay();
        }

        $termEnd = Carbon::parse($endDate, 'Asia/Manila')->endOfDay();
        $today = Carbon::now('Asia/Manila')->endOfDay();

        return $termEnd->lessThan($today) ? $termEnd : $today;
    }

    private function extractStartYear(TermSetting $setting): ?int
    {
        $label = $setting->schoolYearLabel ?? null;
        if (!$label || !preg_match('/^(\d{4})-\d{4}$/', $label, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    private function monthYearMap(int $startYear, int $nextYear): array
    {
        return [
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
    }

    private function parseCutoff(string $cutoff): array
    {
        $parts = explode(':', $cutoff . ':0');
        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    private function isLate(Carbon $ts, int $cutoffHour, int $cutoffMinute): bool
    {
        return $ts->hour > $cutoffHour
            || ($ts->hour === $cutoffHour && $ts->minute > $cutoffMinute);
    }

    private function nullMonth(string $source, bool $partial): array
    {
        return [
            'schoolDays'     => null,
            'schoolDaysUpTo' => null,
            'present'        => null,
            'tardy'          => null,
            'absent'         => null,
            'partial'        => $partial,
            'source'         => $source,
        ];
    }

    private function emptyMonthsShape(): array
    {
        $out = [];
        foreach (config('school.school_months', []) as $monthName) {
            $out[$monthName] = $this->nullMonth('unconfigured', false);
        }
        return $out;
    }
}
