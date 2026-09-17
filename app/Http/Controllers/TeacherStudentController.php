<?php
// app/Http/Controllers/TeacherStudentController.php
namespace App\Http\Controllers;

use App\Models\ParentAccount;
use App\Models\Student;
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
    private function resolveAccess($teacher, string $studentGradeLevel, string $studentSection): ?string
    {
        if ($teacher->isHomeSection($studentGradeLevel, $studentSection)) {
            return 'home';
        }
        if ($teacher->isVisitingSection($studentGradeLevel, $studentSection)) {
            return 'visiting';
        }
        return null;
    }

    /**
     * Refuse a teacher-side write to a term whose card has been admin-locked.
     * Locking is per-term: writing to a different term than the locked one
     * is allowed, even if the locked term is more recent.
     */
    private function rejectIfTermLocked(Student $student, string $term)
    {
        $lockedTerm = $student->reportCardLockedTerm ?? null;
        if ($lockedTerm && $lockedTerm === $term) {
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
            ->get(['_id', 'studentId', 'firstName', 'lastName', 'gradeLevel', 'section', 'parentId', 'reportCard', 'reportCardSubmittedTerm', 'reportCardReleasedTerm', 'reportCardLockedTerm', 'reportCardAdminLocked', 'reportCardSubmittedToAdmin', 'reportCardReleased'])
            ->values();

        $data = $students->map(function ($student) use ($access, $teacher) {
            $row = [
                'id'         => (string) $student->_id,
                'studentId'  => $student->studentId,
                'fullName'   => trim("{$student->firstName} {$student->lastName}"),
                'gradeLevel' => $student->gradeLevel,   // ← ADD THIS
                'section'    => $student->section,
            ];
            if ($access === 'home') {
                $row['forteSubjectCode']           = $teacher->forteSubjectCode;   // ← ADD
                $row['hasParentLink']              = !empty($student->parentId);
                $row['reportCard']                 = $student->reportCard ?? new \stdClass();
                $row['reportCardSubmittedTerm'] = $student->reportCardSubmittedTerm ?? null;
                $row['reportCardReleasedTerm']  = $student->reportCardReleasedTerm  ?? null;
                $row['reportCardLockedTerm']    = $student->reportCardLockedTerm    ?? null;
                $row['reportCardAdminLocked']      = (bool) ($student->reportCardAdminLocked ?? false);
                $row['reportCardSubmittedToAdmin'] = (bool) ($student->reportCardSubmittedToAdmin ?? false);
                $row['reportCardReleased']         = (bool) ($student->reportCardReleased ?? false);
            } else {
                $row['forteSubjectCode']           = $teacher->forteSubjectCode;
                $row['reportCard']                 = $student->reportCard[$teacher->forteSubjectCode] ?? new \stdClass();
                $row['reportCardSubmittedTerm'] = $student->reportCardSubmittedTerm ?? null;
                $row['reportCardReleasedTerm']  = $student->reportCardReleasedTerm  ?? null;
                $row['reportCardLockedTerm']    = $student->reportCardLockedTerm    ?? null;
                $row['reportCardAdminLocked']      = (bool) ($student->reportCardAdminLocked ?? false);
                $row['reportCardSubmittedToAdmin'] = (bool) ($student->reportCardSubmittedToAdmin ?? false);
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
                'gradeLevel'       => $a['gradeLevel'],
                'section'          => $a['section'],
                'role'             => 'home',
                'forteSubjectCode' => $teacher->forteSubjectCode,
            ];
        }
        foreach ($teacher->visitingAssignments ?? [] as $a) {
            $list[] = [
                'gradeLevel'       => $a['gradeLevel'],
                'section'          => $a['section'],
                'role'             => 'visiting',
                'forteSubjectCode' => $teacher->forteSubjectCode,
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
            'reportCardSubmittedTerm'    => $student->reportCardSubmittedTerm ?? null,
            'reportCardReleasedTerm'     => $student->reportCardReleasedTerm  ?? null,
            'reportCardLockedTerm'       => $student->reportCardLockedTerm    ?? null,
            'reportCardAdminLocked'      => (bool) ($student->reportCardAdminLocked ?? false),
            'reportCardSubmittedToAdmin' => (bool) ($student->reportCardSubmittedToAdmin ?? false),
            'reportCardSubmittedAt'      => $student->reportCardSubmittedAt ?? null,
            'reportCardReleased'         => (bool) ($student->reportCardReleased ?? false),
        ];

        if ($access === 'home') {
            $parent = $student->parentId ? ParentAccount::find($student->parentId) : null;
            $data['reportCard'] = $student->reportCard ?? new \stdClass();
            $data['parent'] = $parent ? [
                'fullName'     => trim("{$parent->firstName} {$parent->lastName}"),
                'relationship' => $parent->relationship,
                'email'        => $parent->email,
                'phone'        => $parent->phone,
            ] : null;
        } else {
            $data['forteSubjectCode'] = $teacher->forteSubjectCode;
            $data['reportCard']       = $student->reportCard[$teacher->forteSubjectCode] ?? new \stdClass();
        }

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

        $allowedCodes = [$teacher->forteSubjectCode];

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

                $isAdviserOwn = ($access === 'home' && $subjectCode === $teacher->forteSubjectCode);

                if ($changed && $oldStatus === 'compiled' && !$isAdviserOwn) {
                    if (empty($note)) {
                        return response()->json([
                            'message' => "A note is required when editing an already compiled entry ({$subjectCode} {$term}).",
                        ], 422);
                    }
                    ReportCardRevision::create([
                        'studentId'   => $student->studentId,
                        'subjectCode' => $subjectCode,
                        'term'        => $term,
                        'note'        => $note,
                        'changedAt'   => now(),
                    ]);
                }

                if ($isAdviserOwn) {
                    $newStatus = ($newGrade === null || $newGrade === '') ? 'draft' : 'compiled';
                } else {
                    $newStatus = 'draft';
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
            'term' => 'required|in:T1,T2,T3',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $term = $request->input('term');

        if ($locked = $this->rejectIfTermLocked($student, $term)) {
            return $locked;
        }

        $code = $teacher->forteSubjectCode;
        if (empty($code)) {
            return response()->json(['message' => 'You do not have a forte subject assigned.'], 422);
        }

        $existing = $student->reportCard ?? [];
        $entry = $existing[$code][$term] ?? null;

        if (!$entry || ($entry['grade'] ?? null) === null || $entry['grade'] === '') {
            return response()->json([
                'message' => "Please enter a grade for {$code} {$term} before submitting.",
            ], 422);
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

        if ($this->resolveAccess($teacher, $student->gradeLevel, $student->section) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can compile grades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subjectCode' => ['required', 'string', function ($attribute, $value, $fail) use ($student) {
                $valid = GradeSubjectService::subjectsForGrade($student->gradeLevel);
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
        if ($this->resolveAccess($teacher, $student->gradeLevel, $student->section) !== 'home') {
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

        $required = GradeSubjectService::subjectsForGrade($student->gradeLevel);
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
        $student->reportCardSubmittedToAdmin = true;
        $student->reportCardSubmittedAt      = now();
        $student->save();

        return response()->json([
            'message' => 'Submitted to admin.',
            'data' => [
                'reportCardSubmittedTerm'    => $term,
                'reportCardSubmittedToAdmin' => true,
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
        if ($this->resolveAccess($teacher, $student->gradeLevel, $student->section) !== 'home') {
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
        $student->reportCardSubmittedToAdmin = false;
        $student->reportCardSubmittedAt      = null;
        $student->save();

        return response()->json([
            'message' => 'Submission recalled.',
            'data' => [
                'reportCardSubmittedTerm'    => null,
                'reportCardSubmittedToAdmin' => false,
                'reportCardSubmittedAt'      => null,
            ],
        ]);
    }
}