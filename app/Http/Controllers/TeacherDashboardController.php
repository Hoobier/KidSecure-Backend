<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\TermSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TeacherDashboardController extends Controller
{
    /**
     * GET /api/teacher/dashboard/summary
     */
    public function summary(Request $request)
    {
        $teacher = $request->user();
        $gradeLevel = $teacher->homeGradeLevel;
        $termNumber = TermSetting::current()->activeTermNumber() ?? 1;
        $term = "T{$termNumber}";

        $students = Student::where('gradeLevel', $gradeLevel)
            ->whereIn('enrollmentStatus', ['active', 'inactive'])
            ->get(['studentId', 'firstName', 'lastName', 'reportCard']);

        $attendanceSummary = $this->attendanceSummary($students);
        $homeSectionOverview = $this->homeSectionOverview($students, $term);
        $adviserItems = $this->adviserAttentionItems($students, $term);
        $attendanceConcerns = $this->attendanceConcerns($students);

        $base = [
            'teacher' => [
                'firstName' => $teacher->firstName,
                'lastName' => $teacher->lastName,
                'department' => $teacher->department,
                'homeGradeLevel' => $teacher->homeGradeLevel,
                'forteSubjectCode' => $teacher->forteSubjectCode,
            ],
            'activeTerm' => $term,
            'attendanceSummary' => $attendanceSummary,
            'homeSectionOverview' => $homeSectionOverview,
        ];

        if ($teacher->department === 'preschool') {
            $flatItems = array_merge($adviserItems, $attendanceConcerns);

            return response()->json(array_merge($base, [
                'attentionItems' => $flatItems,
                'pendingBadgeCount' => count($adviserItems),
            ]));
        }

        // Elementary: split adviser vs. subject-teacher duties, add teaching load.
        $subjectTeacherItems = $this->subjectTeacherAttentionItems($teacher, $term);

        return response()->json(array_merge($base, [
            'attentionItems' => [
                'asAdviser' => array_merge($adviserItems, $attendanceConcerns),
                'asSubjectTeacher' => $subjectTeacherItems,
            ],
            'teachingLoad' => [
                'forteSubjectCode' => $teacher->forteSubjectCode,
                'visitingGrades' => $this->elementaryGradesExcept($gradeLevel),
            ],
            'pendingBadgeCount' => count($adviserItems) + count($subjectTeacherItems),
        ]));
    }

    /**
     * Present/absent totals for today, for the given students.
     */
    private function attendanceSummary($students): array
    {
        $studentIds = $students->pluck('studentId')->all();

        $start = Carbon::now('Asia/Manila')->startOfDay()->setTimezone('UTC');
        $end = Carbon::now('Asia/Manila')->endOfDay()->setTimezone('UTC');

        $presentIds = AttendanceLog::whereIn('studentId', $studentIds)
            ->whereBetween('timestamp', [$start, $end])
            ->distinct()
            ->pluck('studentId')
            ->unique();

        $total = $students->count();
        $present = $presentIds->count();

        return [
            'present' => $present,
            'absent' => $total - $present,
            'total' => $total,
        ];
    }

    /**
     * Per-subject status tally for the home grade, current term.
     * e.g. ["MATH" => ["compiled" => 20, "submitted" => 5, "draft" => 2, "not_started" => 0, "pending" => 0]]
     */
    private function homeSectionOverview($students, string $term): array
    {
        $subjects = array_keys(config('school.subjects'));
        $statuses = ['draft', 'submitted', 'compiled', 'pending', 'not_started'];

        $overview = [];
        foreach ($subjects as $code) {
            $overview[$code] = array_fill_keys($statuses, 0);
        }

        foreach ($students as $student) {
            foreach ($subjects as $code) {
                $status = $student->reportCard[$code][$term]['status'] ?? 'not_started';
                $overview[$code][$status] = ($overview[$code][$status] ?? 0) + 1;
            }
        }

        return $overview;
    }

    /**
     * Subjects in the home grade with >=1 "submitted" entry waiting to be compiled.
     * Returns one row per subject that has at least one such student.
     */
    private function adviserAttentionItems($students, string $term): array
    {
        $subjects = array_keys(config('school.subjects'));
        $items = [];

        foreach ($subjects as $code) {
            $count = 0;
            foreach ($students as $student) {
                $status = $student->reportCard[$code][$term]['status'] ?? null;
                if ($status === 'submitted') {
                    $count++;
                }
            }
            if ($count > 0) {
                $items[] = [
                    'type' => 'needs_compile',
                    'subjectCode' => $code,
                    'term' => $term,
                    'count' => $count,
                ];
            }
        }

        return $items;
    }

    /**
     * For an elementary teacher's forte subject, in every grade they visit
     * (all elementary grades except their own home grade): how many students
     * still have no submitted/compiled entry yet this term.
     */
    private function subjectTeacherAttentionItems($teacher, string $term): array
    {
        if (empty($teacher->forteSubjectCode)) {
            return [];
        }

        $items = [];
        foreach ($this->elementaryGradesExcept($teacher->homeGradeLevel) as $gradeLevel) {
            $students = Student::where('gradeLevel', $gradeLevel)
                ->whereIn('enrollmentStatus', ['active', 'inactive'])
                ->get(['reportCard']);

            $notSubmitted = 0;
            foreach ($students as $student) {
                $status = $student->reportCard[$teacher->forteSubjectCode][$term]['status'] ?? null;
                if (!in_array($status, ['submitted', 'compiled'], true)) {
                    $notSubmitted++;
                }
            }

            if ($notSubmitted > 0) {
                $items[] = [
                    'type' => 'needs_submission',
                    'gradeLevel' => $gradeLevel,
                    'subjectCode' => $teacher->forteSubjectCode,
                    'term' => $term,
                    'count' => $notSubmitted,
                ];
            }
        }

        return $items;
    }

    /**
     * Students in this grade with zero attendance taps on each of the last
     * 3 calendar days (not counting today, since today may still be in progress).
     */
    private function attendanceConcerns($students): array
    {
        $studentIds = $students->pluck('studentId')->all();
        $concerns = [];

        $days = [
            Carbon::now('Asia/Manila')->subDay(),
            Carbon::now('Asia/Manila')->subDays(2),
            Carbon::now('Asia/Manila')->subDays(3),
        ];

        foreach ($students as $student) {
            $allAbsent = true;

            foreach ($days as $day) {
                $start = $day->copy()->startOfDay()->setTimezone('UTC');
                $end = $day->copy()->endOfDay()->setTimezone('UTC');

                $hasTap = AttendanceLog::where('studentId', $student->studentId)
                    ->whereBetween('timestamp', [$start, $end])
                    ->exists();

                if ($hasTap) {
                    $allAbsent = false;
                    break;
                }
            }

            if ($allAbsent) {
                $concerns[] = [
                    'type' => 'absent_pattern',
                    'studentId' => $student->studentId,
                    'studentName' => trim("{$student->firstName} {$student->lastName}"),
                    'message' => 'Absent 3 days running',
                ];
            }
        }

        return $concerns;
    }

    /**
     * All elementary grade levels except the given one.
     */
    private function elementaryGradesExcept(string $excludeGrade): array
    {
        $allGrades = config('school.grade_levels');

        return array_values(array_filter($allGrades, function ($grade) use ($excludeGrade) {
            return str_starts_with($grade, 'Grade ') && $grade !== $excludeGrade;
        }));
    }
}