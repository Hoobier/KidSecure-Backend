<?php
// app/Http/Controllers/SchoolYearRolloverController.php
namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\TermSetting;
use App\Services\GradeLevelService;

class SchoolYearRolloverController extends Controller
{
    /**
     * Read-only preview of what happens to every actively enrolled
     * student if rollover runs today. Nothing is written here — this
     * only computes suggested defaults for the admin to review.
     */
    public function preview()
    {
        $termSetting = TermSetting::current();

        $students = Student::where('enrollmentStatus', 'active')
            ->orderBy('lastName')
            ->orderBy('firstName')
            ->get();

        $groups = [];
        $promoting = 0;
        $graduating = 0;

        foreach ($students as $student) {
            $gradeLevel = $student->gradeLevel;

            if (!GradeLevelService::isValidGrade($gradeLevel)) {
                // Skip rather than crash the whole preview over one bad
                // record. These should surface separately so the admin
                // can fix them before running the real rollover.
                continue;
            }

            if (!isset($groups[$gradeLevel])) {
                $isGraduating = GradeLevelService::isGraduatingGrade($gradeLevel);

                $groups[$gradeLevel] = [
                    'gradeLevel' => $gradeLevel,
                    'defaultAction' => $isGraduating ? 'graduate' : 'promote',
                    'nextGradeLevel' => $isGraduating ? null : GradeLevelService::nextGrade($gradeLevel),
                    'students' => [],
                ];
            }

            $suggestedAction = $groups[$gradeLevel]['defaultAction'];

            $groups[$gradeLevel]['students'][] = [
                'id' => (string) $student->_id,
                'studentId' => $student->studentId,
                'name' => trim("{$student->firstName} {$student->lastName}"),
                'section' => $student->section,
                'gradeLevel' => $gradeLevel,
                'suggestedAction' => $suggestedAction,
            ];

            $suggestedAction === 'graduate' ? $graduating++ : $promoting++;
        }

        // Grade 6 first, matching the mockup — highest-stakes group leads.
        $orderedGroups = [];
        foreach (array_reverse(GradeLevelService::order()) as $grade) {
            if (isset($groups[$grade])) {
                $orderedGroups[] = $groups[$grade];
            }
        }

        return response()->json([
            'data' => [
                'schoolYearLabel' => $termSetting->schoolYearLabel,
                'nextSchoolYearLabel' => $termSetting->nextSchoolYearLabel(),
                'summary' => [
                    'promoting' => $promoting,
                    'retaining' => 0,
                    'graduating' => $graduating,
                ],
                'groups' => $orderedGroups,
            ],
        ]);
    }
}