<?php
// app/Http/Controllers/TeacherStudentController.php
namespace App\Http\Controllers;

use App\Models\ParentAccount;
use App\Models\Student;
use App\Models\AttendanceLog;
use App\Models\ReportCardRevision;
use App\Services\GradeSubjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeacherStudentController extends Controller
{
    private const TERMS = ['T1', 'T2', 'T3'];

    // ------------------------------------------------------------------
    // Access resolution (unchanged from Spec 1)
    // ------------------------------------------------------------------
    /**
     * Returns ['role' => 'home'|'visiting', 'subjects' => [...]] for the
     * given class, or null when the teacher has no assignment here.
     * Home role wins when the teacher holds the class both ways.
     */
    private function resolveAccess($teacher, string $gradeLevel, string $section): ?array
    {
        $isHome = $teacher->isHomeSection($gradeLevel, $section);
        $isVisiting = $teacher->isVisitingSection($gradeLevel, $section);

        if (!$isHome && !$isVisiting) return null;

        $subjects = $teacher->subjectsFor($gradeLevel, $section);

        // Preschool is self-contained: the adviser grades every subject for
        // their own class. Their assignments don't carry an explicit subjects
        // array (none existed when the migration ran), so fall back to the
        // full entry-subject list for the grade level.
        if (empty($subjects) && $isHome && $teacher->department === 'preschool') {
            $subjects = \App\Services\GradeSubjectService::entryCodesForGrade($gradeLevel);
        }

        if (empty($subjects)) return null;

        $role = $isHome ? 'home' : 'visiting';
        return ['role' => $role, 'subjects' => $subjects];
    }

    /**
     * Refuse a teacher-side write to a term whose card has been admin-locked.
     * Locking is per-term: writing to a different term than the locked one
     * is allowed, even if the locked term is more recent.
     */
    private function rejectIfTermLocked(Student $student, string $term)
    {
        $lockedTerms = $student->reportCardLockedTerms ?? [];
        if (!is_array($lockedTerms)) $lockedTerms = [];

        if (in_array($term, $lockedTerms, true)) {
            return response()->json([
                'message' => "This {$term} report card is now managed by the school office. Contact the admin for any changes.",
            ], 423);
        }
        return null;
    }

    // ------------------------------------------------------------------
    // GET /api/teacher/students
    // ------------------------------------------------------------------
    public function index(Request $request)
    {
        $teacher = $request->user();

        $gradeLevel = $request->query('gradeLevel');
        $section    = $request->query('section');

        if (!$gradeLevel || !$section) {
            $fallback = collect($teacher->homeAssignments ?? [])
                ->first() ?? collect($teacher->visitingAssignments ?? [])->first();

            if (!$fallback) {
                return response()->json(['message' => 'No classes assigned to this teacher.'], 403);
            }
            $gradeLevel = $gradeLevel ?? $fallback['gradeLevel'];
            $section    = $section    ?? $fallback['section'];
        }

        $access = $this->resolveAccess($teacher, $gradeLevel, $section);
        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this class.'], 403);
        }

        $students = Student::where('gradeLevel', $gradeLevel)
            ->where('section', $section)
            ->whereIn('enrollmentStatus', ['active', 'inactive'])
            ->get(['_id', 'studentId', 'firstName', 'lastName', 'gradeLevel', 'section', 'parentId', 'reportCard', 'reportCardSubmittedTerm', 'reportCardReleasedTerm', 'reportCardLockedTerms', 'observedValues'])
            ->values();

        $data = $students->map(function ($student) use ($access) {
            $row = [
                'id'         => (string) $student->_id,
                'studentId'  => $student->studentId,
                'fullName'   => trim("{$student->firstName} {$student->lastName}"),
                'gradeLevel' => $student->gradeLevel,
                'section'    => $student->section,
                'role'       => $access['role'],
                'subjects'   => $access['subjects'],
                'reportCardSubmittedTerm' => $student->reportCardSubmittedTerm ?? null,
                'reportCardReleasedTerm'  => $student->reportCardReleasedTerm  ?? null,
                'reportCardLockedTerms'   => $student->reportCardLockedTerms ?? [],
            ];

            if ($access['role'] === 'home') {
                $row['hasParentLink'] = !empty($student->parentId);
                $row['reportCard']    = $student->reportCard ?? new \stdClass();
                $row['observedValues'] = $student->observedValues ?? new \stdClass();
            } else {
                // Visiting: only return the subjects this teacher can grade.
                $card = $student->reportCard ?? [];
                $filtered = [];
                foreach ($access['subjects'] as $code) {
                    if (isset($card[$code])) $filtered[$code] = $card[$code];
                }
                $row['reportCard'] = $filtered;
            }

            return $row;
        });

        return response()->json([
            'gradeLevel' => $gradeLevel,
            'section'    => $section,
            'access'     => $access,
            'data'       => $data->values(),
        ]);
    }

    /**
     * GET /api/teacher/classes
     * Returns the teacher's home + visiting classes for the class dropdown.
     */
    public function classes(Request $request)
    {
        $teacher = $request->user();
        $list = [];

        foreach ($teacher->homeAssignments ?? [] as $a) {
            $list[] = [
                'gradeLevel' => $a['gradeLevel'] ?? null,
                'section'    => $a['section'] ?? null,
                'role'       => 'home',
                'subjects'   => $a['subjects'] ?? [],
            ];
        }
        foreach ($teacher->visitingAssignments ?? [] as $a) {
            $list[] = [
                'gradeLevel' => $a['gradeLevel'] ?? null,
                'section'    => $a['section'] ?? null,
                'role'       => 'visiting',
                'subjects'   => $a['subjects'] ?? [],
            ];
        }

        return response()->json(['data' => $list]);
    }


    // ------------------------------------------------------------------
    // GET /api/teacher/students/{id}
    // ------------------------------------------------------------------
    public function show(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this student.'], 403);
        }

        $data = [
            'id'         => (string) $student->_id,
            'studentId'  => $student->studentId,
            'firstName'  => $student->firstName,
            'lastName'   => $student->lastName,
            'gradeLevel' => $student->gradeLevel,
            'section'    => $student->section,
            'role'       => $access['role'],
            'subjects'   => $access['subjects'],
            'reportCardSubmittedTerm'    => $student->reportCardSubmittedTerm ?? null,
            'reportCardReleasedTerm'     => $student->reportCardReleasedTerm  ?? null,
            'reportCardLockedTerms'      => $student->reportCardLockedTerms ?? [],
            'reportCardSubmittedAt'      => $student->reportCardSubmittedAt ?? null,
        ];

        if ($access['role'] === 'home') {
            $parent = $student->parentId ? ParentAccount::find($student->parentId) : null;
            $data['reportCard'] = $student->reportCard ?? new \stdClass();
            $data['observedValues'] = $student->observedValues ?? new \stdClass();
            $data['parent'] = $parent ? [
                'fullName'     => trim("{$parent->firstName} {$parent->lastName}"),
                'relationship' => $parent->relationship,
                'email'        => $parent->email,
                'phone'        => $parent->phone,
            ] : null;
        } else {
            $card = $student->reportCard ?? [];
            $filtered = [];
            foreach ($access['subjects'] as $code) {
                if (isset($card[$code])) $filtered[$code] = $card[$code];
            }
            $data['reportCard'] = $filtered;
        }

        // ------------------------------------------------------------------
        // Today's attendance — visible to both home and visiting teachers.
        // Uses Asia/Manila calendar day boundaries; timestamps stored in UTC.
        // ------------------------------------------------------------------
        $todayManila = \Carbon\Carbon::now('Asia/Manila')->toDateString();
        $startUtc = \Carbon\Carbon::parse($todayManila, 'Asia/Manila')
            ->startOfDay()->setTimezone('UTC');
        $endUtc = \Carbon\Carbon::parse($todayManila, 'Asia/Manila')
            ->endOfDay()->setTimezone('UTC');

        $todayLogs = AttendanceLog::where('studentId', $student->studentId)
            ->whereBetween('timestamp', [$startUtc, $endUtc])
            ->orderBy('timestamp', 'asc')
            ->get(['timestamp']);

        $hasTaps = $todayLogs->count() > 0;
        $firstTap = $hasTaps ? $todayLogs->first()->timestamp : null;
        $lastTap = $hasTaps ? $todayLogs->last()->timestamp : null;

        $data['attendanceToday'] = [
            'date'     => $todayManila,
            'hasTaps'  => $hasTaps,
            'timeIn'   => $firstTap ? \Carbon\Carbon::parse($firstTap)->toIso8601String() : null,
            'timeOut'  => ($hasTaps && $todayLogs->count() > 1)
                ? \Carbon\Carbon::parse($lastTap)->toIso8601String()
                : null,
            'status'   => $hasTaps ? 'present' : 'absent',
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/teacher/students/{id}/report-card
     *
     * Teacher can only write their OWN forte subject's grade for a term.
     *
     * Status rule:
     *   - Adviser writing their OWN forte subject  → straight to 'compiled'
     *     (the adviser IS the grader for that subject in their homeroom).
     *   - Adviser clearing their OWN forte subject → back to 'draft'
     *     (empty-but-compiled would break the completeness check).
     *   - Visiting teacher writing their forte    → 'draft' (adviser compiles).
     */
    public function saveReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this student.'], 403);
        }

        $allowedCodes = $access['subjects'];

        $validator = Validator::make($request->all(), [
            'grades' => ['required', 'array', function ($attribute, $value, $fail) use ($allowedCodes) {
                foreach (array_keys($value) as $code) {
                    if (! in_array($code, $allowedCodes, true)) {
                        $fail("You are not permitted to enter grades for subject: {$code}");
                    }
                }
            }],
            'grades.*.T1.grade' => 'nullable|numeric|min:0|max:100',
            'grades.*.T2.grade' => 'nullable|numeric|min:0|max:100',
            'grades.*.T3.grade' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $incoming = $request->input('grades');
        $existing = $student->reportCard ?? [];
        $note     = $request->input('note');

        foreach ($incoming as $subjectCode => $terms) {
            foreach ($terms as $term => $entry) {
                if (!in_array($term, self::TERMS, true)) continue;

                // Per-term lock check.
                if ($locked = $this->rejectIfTermLocked($student, $term)) {
                    return $locked;
                }

                $oldEntry  = $existing[$subjectCode][$term] ?? null;
                $oldStatus = $oldEntry['status'] ?? null;
                $oldGrade  = $oldEntry['grade'] ?? null;
                $newGrade  = $entry['grade'] ?? null;

                $changed = (string) $oldGrade !== (string) $newGrade;

                // The adviser compiles-to-immediate when they personally grade
                // the subject. For a visiting teacher, always writes to draft.
                $isAdviserOwn = ($access['role'] === 'home');

                // Visiting teachers cannot modify compiled entries at all.
                // Advisers can (they compiled it; they can re-verify).
                if ($changed && $oldStatus === 'compiled' && !$isAdviserOwn) {
                    return response()->json([
                        'message' => "This grade is already compiled. Contact the adviser or admin to request a change.",
                    ], 423);
                }

                if ($isAdviserOwn) {
                    $newStatus = ($newGrade === null || $newGrade === '') ? 'draft' : 'compiled';
                } else {
                    // Visiting teacher: keep an already-compiled entry compiled
                    // when the value hasn't changed. If the value changed, flip
                    // back to draft so the adviser re-verifies.
                    if ($oldStatus === 'compiled' && !$changed) {
                        $newStatus = 'compiled';
                    } else {
                        $newStatus = 'draft';
                    }
                }

                $existing[$subjectCode][$term] = [
                    'grade'  => ($newGrade === null || $newGrade === '') ? null : $newGrade,
                    'status' => $newStatus,
                ];
            }
        }

        $student->reportCard = $existing;
        $student->save();

        return response()->json([
            'message' => 'Report card saved.',
            'data'    => ['grades' => $student->reportCard],
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/teacher/students/{id}/report-card/submit
    // Subject teacher submits their forte subject's term entry to the adviser.
    // ------------------------------------------------------------------
    public function submitReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this student.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'term'        => 'required|in:T1,T2,T3',
            'subjectCode' => ['required', 'string', function ($attribute, $value, $fail) use ($access) {
                if (!in_array($value, $access['subjects'], true)) {
                    $fail("You are not assigned to teach {$value} in this class.");
                }
            }],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $term = $request->input('term');
        $code = $request->input('subjectCode');

        if ($locked = $this->rejectIfTermLocked($student, $term)) {
            return $locked;
        }

        $existing = $student->reportCard ?? [];
        $entry = $existing[$code][$term] ?? null;

        if (!$entry || ($entry['grade'] ?? null) === null || $entry['grade'] === '') {
            return response()->json([
                'message' => "Please enter a grade for {$code} {$term} before submitting.",
            ], 422);
        }

        // No-op if already compiled — a re-submit must not demote it.
        if (($entry['status'] ?? null) === 'compiled') {
            return response()->json([
                'message' => "{$code} {$term} is already compiled.",
                'data'    => ['grades' => $student->reportCard],
            ]);
        }

        $existing[$code][$term] = [
            'grade'  => $entry['grade'],
            'status' => 'submitted',
        ];
        $student->reportCard = $existing;
        $student->save();

        return response()->json([
            'message' => "{$code} {$term} submitted to adviser.",
            'data'    => ['grades' => $student->reportCard],
        ]);
    }

    /**
     * POST /api/teacher/students/{id}/report-card/compile
     * Adviser accepts a submitted entry from a visiting teacher and marks
     * it compiled. The adviser's OWN forte subject never comes through here
     * — it's compiled at save time in saveReportCard().
     */
    public function compileReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if (($this->resolveAccess($teacher, $student->gradeLevel, $student->section)['role'] ?? null) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can compile grades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subjectCode' => ['required', 'string', function ($attribute, $value, $fail) use ($student) {
                $valid = GradeSubjectService::entryCodesForGrade($student->gradeLevel);
                if (!in_array($value, $valid, true)) {
                    $fail("Subject {$value} is not offered at {$student->gradeLevel}.");
                }
            }],
            'term' => 'required|in:T1,T2,T3',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subjectCode = $request->input('subjectCode');
        $term        = $request->input('term');
        $note        = $request->input('note');

        if ($locked = $this->rejectIfTermLocked($student, $term)) {
            return $locked;
        }

        $existing = $student->reportCard ?? [];
        $entry    = $existing[$subjectCode][$term] ?? null;
        $status   = $entry['status'] ?? null;
        $grade    = $entry['grade'] ?? null;

        if ($status !== 'submitted') {
            return response()->json([
                'message' => "This entry has not been submitted yet ({$subjectCode} {$term}).",
            ], 422);
        }
        if ($grade === null || $grade === '') {
            return response()->json([
                'message' => "Cannot compile {$subjectCode} {$term} — no grade entered yet.",
            ], 422);
        }

        $existing[$subjectCode][$term] = [
            'grade'  => $grade,
            'status' => 'compiled',
        ];
        $student->reportCard = $existing;
        $student->save();

        return response()->json([
            'message' => "{$subjectCode} {$term} compiled.",
            'data'    => ['grades' => $student->reportCard],
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/teacher/students/{id}/report-card/submit-to-admin
    // Adviser sends the whole card to admin, once every subject at this
    // grade is compiled for the current term.
    // ------------------------------------------------------------------
    public function submitToAdmin(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        if (($this->resolveAccess($teacher, $student->gradeLevel, $student->section)['role'] ?? null) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can submit to admin.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'term' => 'required|in:T1,T2,T3',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $term = $request->input('term');

        if ($locked = $this->rejectIfTermLocked($student, $term)) {
            return $locked;
        }

        $required = GradeSubjectService::entryCodesForGrade($student->gradeLevel);
        $card = $student->reportCard ?? [];

        $incomplete = [];
        foreach ($required as $code) {
            $status = $card[$code][$term]['status'] ?? null;
            if ($status !== 'compiled') {
                $incomplete[] = $code;
            }
        }

        if (!empty($incomplete)) {
            return response()->json([
                'message' => 'Every subject must be compiled before submitting to admin.',
                'incompleteSubjects' => $incomplete,
            ], 422);
        }

        // Write the new fields. Keep the legacy flags in sync for now, so
        // anything that still reads them (rollover, misc) doesn't break.
        $student->reportCardSubmittedTerm    = $term;
        $student->reportCardSubmittedAt      = now();
        $student->save();

        return response()->json([
            'message' => 'Submitted to admin.',
            'data' => [
                'reportCardSubmittedTerm'    => $term,
                'reportCardSubmittedAt'      => $student->reportCardSubmittedAt,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/teacher/students/{id}/report-card/recall
    // Adviser pulls back a submitted card before admin acts.
    // ------------------------------------------------------------------
    public function recallSubmission(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        if (($this->resolveAccess($teacher, $student->gradeLevel, $student->section)['role'] ?? null) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can recall a submission.'], 403);
        }

        $submittedTerm = $student->reportCardSubmittedTerm ?? null;
        if (!$submittedTerm) {
            return response()->json(['message' => 'This card has not been submitted to admin.'], 422);
        }
        if (($student->reportCardReleasedTerm ?? null) === $submittedTerm) {
            return response()->json(['message' => 'This card has already been released to the parent — it cannot be recalled.'], 422);
        }

        $student->reportCardSubmittedTerm    = null;
        $student->reportCardSubmittedAt      = null;
        $student->save();

        return response()->json([
            'message' => 'Submission recalled.',
            'data' => [
                'reportCardSubmittedTerm'    => null,
                'reportCardSubmittedAt'      => null,
            ],
        ]);
    }

    /**
     * POST /api/teacher/students/{id}/observed-values
     * Adviser-only. Save observed value ratings for one term.
     * Term must be the currently active term and not locked.
     */
    public function saveObservedValues(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access || $access['role'] !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can enter observed values.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'term'   => 'required|in:T1,T2,T3',
            'values' => 'required|array',
            'values.*' => 'required|string|in:AO,SO,RO,NO',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $term = $request->input('term');

        // Only the active term is editable by the adviser.
        $activeTermNumber = \App\Models\TermSetting::current()->activeTermNumber() ?? 1;
        $activeTerm = "T{$activeTermNumber}";
        if ($term !== $activeTerm) {
            return response()->json([
                'message' => 'Only the currently active term can be edited.',
            ], 422);
        }

        if ($locked = $this->rejectIfTermLocked($student, $term)) {
            return $locked;
        }

        // Validate that every value code is one of the config-defined core values.
        $validCoreValues = array_keys(config('school.observed_values', []));
        $incoming = $request->input('values');
        foreach (array_keys($incoming) as $coreValueCode) {
            if (!in_array($coreValueCode, $validCoreValues, true)) {
                return response()->json([
                    'message' => "Unknown core value: {$coreValueCode}",
                ], 422);
            }
        }

        $existing = $student->observedValues ?? [];
        if (!is_array($existing)) $existing = [];

        $existing[$term] = $incoming;
        $student->observedValues = $existing;
        $student->save();

        return response()->json([
            'message' => 'Observed values saved.',
            'data'    => ['observedValues' => $student->observedValues],
        ]);
    }

        public function attendance(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access || $access['role'] !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can view attendance.'], 403);
        }

        $service = app(\App\Services\AttendanceDeriveService::class);
        $months = $service->mergedForStudent($student);

        return response()->json([
            'data' => [
                'months' => $months,
                'locked' => $student->attendanceLocked(),
            ],
        ]);
    }

    public function saveAttendance(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel, $student->section);
        if (!$access || $access['role'] !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can enter attendance.'], 403);
        }

        if ($student->attendanceLocked()) {
            return response()->json([
                'message' => 'Attendance is now managed by the school office.',
            ], 423);
        }

        $validator = Validator::make($request->all(), [
            'months' => 'required|array',
            'months.*.present' => 'nullable|integer|min:0|max:31',
            'months.*.tardy'   => 'nullable|integer|min:0|max:31',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $setting = \App\Models\TermSetting::current();
        $schoolDays = $setting->monthlySchoolDays ?? [];
        $incoming = $request->input('months');

        foreach ($incoming as $monthName => $entry) {
            if (!in_array($monthName, config('school.school_months', []), true)) {
                return response()->json(['message' => "Unknown month: {$monthName}"], 422);
            }
            $max = $schoolDays[$monthName] ?? null;
            $present = (int) ($entry['present'] ?? 0);
            $tardy   = (int) ($entry['tardy'] ?? 0);

            if ($max !== null && ($present + $tardy) > $max) {
                return response()->json([
                    'message' => "Present + tardy for {$monthName} exceeds school days ({$max}).",
                ], 422);
            }
        }

        $clean = [];
        foreach ($incoming as $monthName => $entry) {
            $present = $entry['present'] ?? null;
            $tardy   = $entry['tardy'] ?? null;
            if ($present === null && $tardy === null) continue;
            $clean[$monthName] = [
                'present' => $present === null ? 0 : (int) $present,
                'tardy'   => $tardy === null ? 0 : (int) $tardy,
            ];
        }

        $student->attendanceByMonth = $clean;
        $student->save();

        return response()->json([
            'message' => 'Attendance saved.',
            'data'    => ['attendanceByMonth' => $student->attendanceByMonth],
        ]);
    }
}