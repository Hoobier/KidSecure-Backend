<?php
// app/Http/Controllers/TeacherStudentController.php
namespace App\Http\Controllers;

use App\Models\ParentAccount;
use App\Models\Student;
use Illuminate\Http\Request;
use App\Models\ReportCardRevision;
use Illuminate\Support\Facades\Validator;

class TeacherStudentController extends Controller
{
    /**
     * Determines what access level the given teacher has over a student's grade.
     * Returns 'home', 'visiting', or null (no access).
     */
    private function resolveAccess($teacher, string $studentGradeLevel): ?string
    {
        if ($studentGradeLevel === $teacher->homeGradeLevel) {
            return 'home';
        }

        $isElementaryGrade = str_starts_with($studentGradeLevel, 'Grade ');

        if (
            $teacher->department === 'elementary'
            && !empty($teacher->forteSubjectCode)
            && $isElementaryGrade
        ) {
            return 'visiting';
        }

        return null;
    }

    /**
     * GET /api/teacher/students?gradeLevel=Grade 3
     *
     * Defaults to the teacher's home grade if gradeLevel is omitted.
     * Home access: full roster. Visiting access: name/section + own subject only.
     */
    public function index(Request $request)
    {
        $teacher = $request->user();
        $gradeLevel = $request->query('gradeLevel', $teacher->homeGradeLevel);

        $access = $this->resolveAccess($teacher, $gradeLevel);

        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this grade level.'], 403);
        }

        $students = Student::where('gradeLevel', $gradeLevel)
            ->whereIn('enrollmentStatus', ['active', 'inactive'])
            ->get(['_id', 'studentId', 'firstName', 'lastName', 'section', 'parentId', 'reportCard'])
            ->sortBy('lastName')
            ->values();

        $data = $students->map(function ($student) use ($access, $teacher) {
            $row = [
                'id' => $student->_id,
                'studentId' => $student->studentId,
                'fullName' => trim("{$student->firstName} {$student->lastName}"),
                'section' => $student->section,
            ];

            if ($access === 'home') {
                $row['hasParentLink'] = !empty($student->parentId);
                $row['reportCard'] = $student->reportCard ?? new \stdClass();
            } else {
                $subjectEntry = $student->reportCard[$teacher->forteSubjectCode] ?? new \stdClass();
                $row['forteSubjectCode'] = $teacher->forteSubjectCode;
                $row['reportCard'] = $subjectEntry;
            }

            return $row;
        });

        return response()->json([
            'gradeLevel' => $gradeLevel,
            'access' => $access,
            'data' => $data->values(),
        ]);
    }

    /**
     * GET /api/teacher/students/{id}
     *
     * Home access: full detail + parent block + all-subject report card.
     * Visiting access: basic info + own subject's report card entry only.
     */
    public function show(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel);

        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this student.'], 403);
        }

        $data = [
            'id' => $student->_id,
            'studentId' => $student->studentId,
            'firstName' => $student->firstName,
            'lastName' => $student->lastName,
            'gradeLevel' => $student->gradeLevel,
            'section' => $student->section,
        ];

        if ($access === 'home') {
            $parent = $student->parentId ? ParentAccount::find($student->parentId) : null;

            $data['reportCard'] = $student->reportCard ?? new \stdClass();
            $data['reportCardReleased'] = $student->reportCardReleased ?? false;
            $data['parent'] = $parent ? [
                'fullName' => trim("{$parent->firstName} {$parent->lastName}"),
                'relationship' => $parent->relationship,
                'email' => $parent->email,
                'phone' => $parent->phone,
            ] : null;
        } else {
            $data['forteSubjectCode'] = $teacher->forteSubjectCode;
            $data['reportCard'] = $student->reportCard[$teacher->forteSubjectCode] ?? new \stdClass();
        }

        return response()->json(['data' => $data]);
    }

    public function saveReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel);

        if (!$access) {
            return response()->json(['message' => 'Unauthorized for this student.'], 403);
        }

        // Determine which subject codes this teacher is allowed to write here.
        $allowedCodes = $access === 'home'
            ? array_keys(config('school.subjects'))
            : [$teacher->forteSubjectCode];

        $validator = Validator::make($request->all(), [
            'grades' => ['required', 'array', function ($attribute, $value, $fail) use ($allowedCodes) {
                foreach (array_keys($value) as $code) {
                    if (! in_array($code, $allowedCodes, true)) {
                        $fail("You are not permitted to enter grades for subject: {$code}");
                    }
                }
            }],
            'grades.*.T1.grade' => 'nullable|numeric|min:0|max:100',
            'grades.*.T1.status' => 'nullable|in:draft,submitted,compiled,pending',
            'grades.*.T2.grade' => 'nullable|numeric|min:0|max:100',
            'grades.*.T2.status' => 'nullable|in:draft,submitted,compiled,pending',
            'grades.*.T3.grade' => 'nullable|numeric|min:0|max:100',
            'grades.*.T3.status' => 'nullable|in:draft,submitted,compiled,pending',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $incoming = $request->input('grades');
        $existing = $student->reportCard ?? [];
        $note = $request->input('note');
        $touchedFinalized = false;

        foreach ($incoming as $subjectCode => $terms) {
            foreach ($terms as $term => $entry) {
                $oldEntry = $existing[$subjectCode][$term] ?? null;
                $oldStatus = $oldEntry['status'] ?? null;
                $oldGrade = $oldEntry['grade'] ?? null;
                $newGrade = $entry['grade'] ?? null;
                $newStatus = $entry['status'] ?? null;

                $changed = $oldGrade != $newGrade || $oldStatus != $newStatus;
                $wasFinalized = $oldStatus === 'compiled' || $student->reportCardReleased;

                if ($changed && $wasFinalized) {
                    if (empty($note)) {
                        return response()->json([
                            'message' => "A note is required when editing an already compiled or released entry ({$subjectCode} {$term}).",
                        ], 422);
                    }

                    $touchedFinalized = true;

                    ReportCardRevision::create([
                        'studentId' => $student->studentId,
                        'subjectCode' => $subjectCode,
                        'term' => $term,
                        'note' => $note,
                        'changedAt' => now(),
                    ]);
                }
            }
        }

        // Merge only the incoming subjects into existing data — a teacher
        // (especially visiting) must never overwrite subjects they didn't send.
        $merged = $existing;
        foreach ($incoming as $subjectCode => $terms) {
            $merged[$subjectCode] = array_merge($merged[$subjectCode] ?? [], $terms);
        }

        $student->reportCard = $merged;
        $student->save();

        if ($touchedFinalized && $student->reportCardReleased) {
            $student->reportCardReleased = false;
            $student->reportCardReleasedAt = null;
            $student->save();

            try {
                app(\App\Services\FirebaseRealtimeService::class)->removeReportCard($student->studentId);
            } catch (\Throwable $e) {
                \Log::error("RTDB removeReportCard failed after teacher edit-triggered unrelease for {$student->studentId}: " . $e->getMessage());
            }
        } elseif ($student->reportCardReleased) {
            try {
                app(\App\Services\FirebaseRealtimeService::class)->mirrorReportCard($student);
            } catch (\Throwable $e) {
                \Log::error("RTDB mirrorReportCard failed after teacher report card save for {$student->studentId}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Report card saved.',
            'data' => [
                'grades' => $student->reportCard,
                'reportCardReleased' => $student->reportCardReleased,
                'reportCardReleasedAt' => $student->reportCardReleasedAt,
            ],
        ]);
    }

    public function compileReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $access = $this->resolveAccess($teacher, $student->gradeLevel);

        if ($access !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can compile grades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subjectCode' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!in_array($value, array_keys(config('school.subjects')), true)) {
                    $fail("Unknown subject code: {$value}");
                }
            }],
            'term' => 'required|in:T1,T2,T3',
            'grade' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subjectCode = $request->input('subjectCode');
        $term = $request->input('term');
        $note = $request->input('note');

        $existing = $student->reportCard ?? [];
        $oldEntry = $existing[$subjectCode][$term] ?? null;
        $oldStatus = $oldEntry['status'] ?? null;
        $oldGrade = $oldEntry['grade'] ?? null;

        $hasNewGrade = $request->filled('grade');

        if (!$hasNewGrade && $oldStatus !== 'submitted') {
            return response()->json([
                'message' => "This entry has not been submitted yet ({$subjectCode} {$term}). Provide a grade to enter it directly, or wait for the subject teacher to submit.",
            ], 422);
        }

        $newGrade = $hasNewGrade ? $request->input('grade') : $oldGrade;
        $changed = $oldGrade != $newGrade || $oldStatus !== 'compiled';
        $wasFinalized = $oldStatus === 'compiled' || $student->reportCardReleased;

        if ($changed && $wasFinalized && empty($note)) {
            return response()->json([
                'message' => "A note is required when editing an already compiled or released entry ({$subjectCode} {$term}).",
            ], 422);
        }

        if ($changed && $wasFinalized) {
            ReportCardRevision::create([
                'studentId' => $student->studentId,
                'subjectCode' => $subjectCode,
                'term' => $term,
                'note' => $note,
                'changedAt' => now(),
            ]);
        }

        $merged = $existing;
        $merged[$subjectCode][$term] = ['grade' => $newGrade, 'status' => 'compiled'];
        $student->reportCard = $merged;
        $student->save();

        if ($changed && $wasFinalized && $student->reportCardReleased) {
            $student->reportCardReleased = false;
            $student->reportCardReleasedAt = null;
            $student->save();

            try {
                app(\App\Services\FirebaseRealtimeService::class)->removeReportCard($student->studentId);
            } catch (\Throwable $e) {
                \Log::error("RTDB removeReportCard failed after compile-triggered unrelease for {$student->studentId}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => "{$subjectCode} {$term} compiled.",
            'data' => ['grades' => $student->reportCard],
        ]);
    }

    /**
     * POST /api/teacher/students/{id}/report-card/release
     * Adviser-only. Releases even with subjects still "pending" (soft-block).
     */
    public function releaseReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if ($this->resolveAccess($teacher, $student->gradeLevel) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can release report cards.'], 403);
        }

        $student->reportCardReleased = true;
        $student->reportCardReleasedAt = now();
        $student->save();

        try {
            app(\App\Services\FirebaseRealtimeService::class)->mirrorReportCard($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirrorReportCard failed after teacher release for {$student->studentId}: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Report card released.',
            'data' => [
                'reportCardReleased' => true,
                'reportCardReleasedAt' => $student->reportCardReleasedAt,
            ],
        ]);
    }

    /**
     * POST /api/teacher/students/{id}/report-card/unrelease
     * Adviser-only.
     */
    public function unreleaseReportCard(Request $request, $id)
    {
        $teacher = $request->user();
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if ($this->resolveAccess($teacher, $student->gradeLevel) !== 'home') {
            return response()->json(['message' => 'Only the advisory teacher can unrelease report cards.'], 403);
        }

        $student->reportCardReleased = false;
        $student->reportCardReleasedAt = null;
        $student->save();

        try {
            app(\App\Services\FirebaseRealtimeService::class)->removeReportCard($student->studentId);
        } catch (\Throwable $e) {
            \Log::error("RTDB removeReportCard failed after teacher unrelease for {$student->studentId}: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Report card release revoked.',
            'data' => ['reportCardReleased' => false, 'reportCardReleasedAt' => null],
        ]);
    }
}