<?php
// app/Http/Controllers/SchoolYearRolloverController.php
namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\TermSetting;
use App\Services\GradeLevelService;
use App\Models\AcademicRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ParentAccount;
use App\Models\RfidCard;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\Log;



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

    public function commit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'newSchoolYearLabel' => 'required|string|max:20',
            'decisions' => 'required|array|min:1',
            'decisions.*.studentId' => 'required|string',
            'decisions.*.action' => 'required|in:promote,retain,graduate',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Something is wrong with the submitted decisions.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $termSetting = TermSetting::current();
        $decisions = $request->input('decisions');

        $students = [];
        foreach ($decisions as $decision) {
            $student = Student::find($decision['studentId']);

            if (!$student || $student->enrollmentStatus !== 'active') {
                return response()->json([
                    'message' => "Student {$decision['studentId']} could not be found or is not active.",
                ], 422);
            }

            if ($decision['action'] === 'promote' && GradeLevelService::isGraduatingGrade($student->gradeLevel)) {
                return response()->json([
                    'message' => "{$student->firstName} {$student->lastName} is in the highest grade level and can't be promoted — choose graduate or retain instead.",
                ], 422);
            }

            $students[$decision['studentId']] = ['student' => $student, 'action' => $decision['action']];
        }

        // Firebase Auth accounts to disable AFTER the Mongo transaction commits
        // — never inside it, since Firebase has no way to "roll back" if a
        // later student in the same batch fails validation.
        $parentUidsToFreeze = [];

        DB::transaction(function () use ($students, $termSetting, $request, &$parentUidsToFreeze) {
            foreach ($students as $entry) {
                $student = $entry['student'];
                $action = $entry['action'];

                AcademicRecord::create([
                    'studentId' => $student->studentId,
                    'schoolYearLabel' => $termSetting->schoolYearLabel,
                    'gradeLevel' => $student->gradeLevel,
                    'section' => $student->section,
                    'finalStatus' => $action === 'promote' ? 'promoted' : ($action === 'retain' ? 'retained' : 'graduated'),
                    'reportCard' => $student->reportCard,
                ]);

                if ($action === 'promote') {
                    $student->gradeLevel = GradeLevelService::nextGrade($student->gradeLevel);
                    $student->save();
                } elseif ($action === 'graduate') {
                    $student->enrollmentStatus = 'graduated';
                    $student->save();

                    if (!empty($student->rfidTag)) {
                        RfidCard::where('tagId', $student->rfidTag)->update([
                            'status' => 'inactive',
                            'deactivatedDate' => now(),
                        ]);
                    }

                    $parentAccount = ParentAccount::find($student->parentId);
                    if ($parentAccount
                        && !empty($parentAccount->firebaseUid)
                        && $this->allLinkedStudentsHaveLeft($parentAccount)) {
                        $parentUidsToFreeze[$parentAccount->firebaseUid] = true;
                    }
                }
            }

            $termSetting->schoolYearLabel = $request->input('newSchoolYearLabel');
            $termSetting->terms = [
                ['termNumber' => 1, 'startDate' => null, 'endDate' => null],
                ['termNumber' => 2, 'startDate' => null, 'endDate' => null],
                ['termNumber' => 3, 'startDate' => null, 'endDate' => null],
            ];
            $termSetting->rolloverStatus = 'completed';
            $termSetting->rolloverCompletedAt = now();
            $termSetting->save();
        });

        // Transaction succeeded — now safe to touch Firebase. A failure here
        // doesn't undo the promotions (they're real and already committed);
        // it just means a parent freeze needs a manual retry, which is a far
        // smaller problem than an incorrectly-reverted graduation.
        foreach (array_keys($parentUidsToFreeze) as $uid) {
            try {
                app(FirebaseService::class)->disableParentAccount($uid);
            } catch (\Throwable $e) {
                Log::error("Failed to disable Firebase account {$uid} after rollover: " . $e->getMessage());
            }
        }

        return response()->json(['message' => 'School year rollover completed.']);
    }

    private function allLinkedStudentsHaveLeft(ParentAccount $parentAccount): bool
    {
        $studentIds = $parentAccount->studentIds ?? [];

        if (empty($studentIds)) {
            return false;
        }

        $stillActiveCount = Student::whereIn('_id', $studentIds)
            ->where('enrollmentStatus', 'active')
            ->count();

        return $stillActiveCount === 0;
    }
}