<?php
// app/Http/Controllers/TeacherDashboardController.php
namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\TermSetting;
use App\Services\GradeSubjectService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TeacherDashboardController extends Controller
{
    public function summary(Request $request)
    {
        $teacher = $request->user();
        $termNumber = TermSetting::current()->activeTermNumber() ?? 1;
        $term = "T{$termNumber}";

        $homeAssignments = $teacher->homeAssignments ?? [];
        $visitingAssignments = $teacher->visitingAssignments ?? [];

        $homeSections = $this->buildHomeSections($teacher, $term);
        $visitingClasses = $this->buildVisitingClasses($teacher, $term);

        $stats = $this->buildStats($homeSections, $visitingClasses);

        // Attendance across all home-section students
        $attendanceToday = null;
        if (!empty($homeAssignments)) {
            $allHomeStudents = collect();
            foreach ($homeAssignments as $a) {
                $allHomeStudents = $allHomeStudents->merge(
                    Student::where('gradeLevel', $a['gradeLevel'] ?? null)
                        ->where('section', $a['section'] ?? null)
                        ->whereIn('enrollmentStatus', ['active', 'inactive'])
                        ->get(['studentId', 'firstName', 'lastName', 'gradeLevel', 'section'])
                );
            }
            if ($allHomeStudents->isNotEmpty()) {
                $attendanceToday = $this->attendanceSummary($allHomeStudents);
                $attendanceConcerns = $this->attendanceConcerns($allHomeStudents);
            } else {
                $attendanceConcerns = [];
            }
        } else {
            $attendanceConcerns = [];
        }

        $compileItems = $this->buildCompileItems($teacher, $term);
        $gradeItems = $this->buildGradeItems($teacher, $term);

        return response()->json([
            'teacher' => [
                'firstName' => $teacher->firstName ?? null,
                'middleName' => $teacher->middleName ?? null,
                'lastName' => $teacher->lastName ?? null,
                'department' => $teacher->department ?? null,
                'subjects' => $teacher->allAssignedSubjects(),
            ],
            'term' => $term,
            'stats' => $stats,
            'homeSections' => $homeSections,
            'visitingClasses' => $visitingClasses,
            'attendanceToday' => $attendanceToday,
            'attendanceConcerns' => $attendanceConcerns,
            'compileItems' => $compileItems,
            'gradeItems' => $gradeItems,
        ]);
    }

    private function buildHomeSections($teacher, string $term): array
    {
        $sections = [];
        foreach ($teacher->homeAssignments ?? [] as $a) {
            $grade = $a['gradeLevel'] ?? null;
            $section = $a['section'] ?? null;
            if (!$grade || !$section) continue;

            $students = Student::where('gradeLevel', $grade)
                ->where('section', $section)
                ->whereIn('enrollmentStatus', ['active', 'inactive'])
                ->get();

            $entryCodes = GradeSubjectService::entryCodesForGrade($grade);

            $compiledCount = 0;
            $readyToSubmitCount = 0;
            $submittedCount = 0;
            $releasedCount = 0;
            $awaitingCompileCount = 0;

            foreach ($students as $student) {
                $reportCard = $student->reportCard ?? [];
                $allCompiled = true;
                $hasSubmitted = false;
                $allReady = true;

                foreach ($entryCodes as $subj) {
                    $status = $reportCard[$subj][$term]['status'] ?? 'not_started';
                    if ($status !== 'compiled') {
                        $allCompiled = false;
                    }
                    if ($status === 'submitted') {
                        $hasSubmitted = true;
                    }
                }

                if ($allCompiled) {
                    $compiledCount++;
                    $reportSubmitted = $reportCard['reportCardSubmittedTerm'] ?? null;
                    $reportReleased = $reportCard['reportCardReleasedTerm'] ?? null;
                    if ($reportReleased === $term) {
                        $releasedCount++;
                    } elseif ($reportSubmitted === $term) {
                        $submittedCount++;
                    } else {
                        $readyToSubmitCount++;
                    }
                }

                foreach ($entryCodes as $subj) {
                    $status = $reportCard[$subj][$term]['status'] ?? 'not_started';
                    if ($status === 'submitted') {
                        $awaitingCompileCount++;
                        break; // count student once per section, not per subject
                    }
                }
            }

            $sections[] = [
                'gradeLevel' => $grade,
                'section' => $section,
                'studentCount' => $students->count(),
                'compiledCount' => $compiledCount,
                'readyToSubmitCount' => $readyToSubmitCount,
                'submittedCount' => $submittedCount,
                'releasedCount' => $releasedCount,
                'awaitingCompileCount' => $awaitingCompileCount,
            ];
        }
        return $sections;
    }

    private function buildVisitingClasses($teacher, string $term): array
    {
        $classes = [];
        foreach ($teacher->visitingAssignments ?? [] as $a) {
            $grade = $a['gradeLevel'] ?? null;
            $section = $a['section'] ?? null;
            $subjects = $a['subjects'] ?? [];
            if (!$grade || !$section) continue;

            $students = Student::where('gradeLevel', $grade)
                ->where('section', $section)
                ->whereIn('enrollmentStatus', ['active', 'inactive'])
                ->get();

            foreach ($subjects as $subj) {
                $graded = 0;
                foreach ($students as $student) {
                    $reportCard = $student->reportCard ?? [];
                    $status = $reportCard[$subj][$term]['status'] ?? null;
                    if ($status !== null && $status !== '') {
                        $graded++;
                    }
                }
                $count = $students->count();
                $missing = $count - $graded;
                $classes[] = [
                    'gradeLevel' => $grade,
                    'section' => $section,
                    'subjectCode' => $subj,
                    'studentCount' => $count,
                    'gradedCount' => $graded,
                    'missingCount' => $missing,
                ];
            }
        }
        return $classes;
    }

    private function buildStats(array $homeSections, array $visitingClasses): array
    {
        $homeClassCount = count($homeSections);
        $visitingClassCount = count($visitingClasses);
        $totalHomeStudents = array_sum(array_column($homeSections, 'studentCount'));
        $gradesToEnter = array_sum(array_column($visitingClasses, 'missingCount'));
        $studentsAwaitingCompile = array_sum(array_column($homeSections, 'awaitingCompileCount'));

        return [
            'homeClassCount' => $homeClassCount,
            'visitingClassCount' => $visitingClassCount,
            'totalHomeStudents' => $totalHomeStudents,
            'gradesToEnter' => $gradesToEnter,
            'studentsAwaitingCompile' => $studentsAwaitingCompile,
        ];
    }

    private function buildCompileItems($teacher, string $term): array
    {
        $items = [];
        foreach ($teacher->homeAssignments ?? [] as $a) {
            $grade = $a['gradeLevel'] ?? null;
            $section = $a['section'] ?? null;
            if (!$grade || !$section) continue;

            $students = Student::where('gradeLevel', $grade)
                ->where('section', $section)
                ->whereIn('enrollmentStatus', ['active', 'inactive'])
                ->get();

            $entryCodes = GradeSubjectService::entryCodesForGrade($grade);
            foreach ($students as $student) {
                $reportCard = $student->reportCard ?? [];
                foreach ($entryCodes as $subj) {
                    $status = $reportCard[$subj][$term]['status'] ?? null;
                    if ($status === 'submitted') {
                        $items[] = [
                            'gradeLevel' => $grade,
                            'section' => $section,
                            'studentId' => $student->studentId,
                            'fullName' => trim(($student->firstName ?? '') . ' ' . ($student->lastName ?? '')),
                            'subjectCode' => $subj,
                            'term' => $term,
                        ];
                    }
                }
            }
        }
        return $items;
    }

    private function buildGradeItems($teacher, string $term): array
    {
        $items = [];
        $visitingClasses = $this->buildVisitingClasses($teacher, $term);
        foreach ($visitingClasses as $cls) {
            if ($cls['missingCount'] > 0) {
                $items[] = [
                    'gradeLevel' => $cls['gradeLevel'],
                    'section' => $cls['section'],
                    'subjectCode' => $cls['subjectCode'],
                    'missingCount' => $cls['missingCount'],
                ];
            }
        }
        return $items;
    }

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
            'notYetTapped' => 0,
        ];
    }

    private function attendanceConcerns($students): array
    {
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
                    'studentId' => $student->studentId,
                    'fullName' => trim(($student->firstName ?? '') . ' ' . ($student->lastName ?? '')),
                    'gradeLevel' => $student->gradeLevel ?? null,
                    'section' => $student->section ?? null,
                    'daysMissing' => 3,
                ];
            }
        }
        return $concerns;
    }

    private function visitingGradesForTeacher($teacher): array
    {
        return $teacher->visitingGradeLevels();
    }
}
