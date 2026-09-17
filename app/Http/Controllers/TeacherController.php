<?php
// app/Http/Controllers/TeacherController.php
namespace App\Http\Controllers;

use App\Mail\TeacherAccountCreated;
use App\Models\Teacher;
use App\Services\GradeSubjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class TeacherController extends Controller
{
    private const SECTION_OPTIONS = ['A', 'B', 'C'];

    // ------------------------------------------------------------------
    // GET /api/teachers
    // ------------------------------------------------------------------
    public function index(Request $request)
    {
        $perPage    = min((int) $request->query('per_page', 20), 100);
        $search     = trim((string) $request->query('search', ''));
        $department = $request->query('department');
        $status     = $request->query('status', 'active');

        $query = Teacher::query();

        if ($search !== '') {
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            if (count($tokens) > 0) {
                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $tok) {
                        $q->where(function ($inner) use ($tok) {
                            $inner->where('firstName', 'like', "%{$tok}%")
                                  ->orWhere('middleName', 'like', "%{$tok}%")
                                  ->orWhere('lastName', 'like', "%{$tok}%")
                                  ->orWhere('email', 'like', "%{$tok}%");
                        });
                    }
                });
            }
        }

        if ($department && in_array($department, ['elementary', 'preschool'], true)) {
            $query->where('department', $department);
        }

        if ($status && in_array($status, ['active', 'deleted'], true)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $items = $query->orderBy('lastName')->orderBy('firstName')->get();

        $data = collect($items)->map(function (Teacher $t) {
            $fullName = trim(($t->firstName ?? '') . ' ' . ($t->middleName ?? '') . ' ' . ($t->lastName ?? ''));
            $fullName = preg_replace('/\s+/', ' ', $fullName);

            return [
                'id'                     => (string) $t->_id,
                'firstName'              => $t->firstName,
                'middleName'             => $t->middleName,
                'lastName'               => $t->lastName,
                'fullName'               => $fullName,
                'email'                  => $t->email,
                'department'             => $t->department,
                'forteSubjectCode'       => $t->forteSubjectCode,
                'homeAssignmentsCount'   => count($t->homeAssignments ?? []),
                'visitingAssignmentsCount' => count($t->visitingAssignments ?? []),
                'status'                 => $t->status ?? 'active',
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'    => $total,
                'per_page' => $perPage,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/teachers
    // ------------------------------------------------------------------
    public function store(Request $request)
    {
        $validator = $this->makeValidator($request);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the teacher details and try again.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $tempPassword = $this->generateTempPassword();

        $teacher = new Teacher();
        $teacher->firstName       = $data['firstName'];
        $teacher->middleName      = $data['middleName'] ?? '';
        $teacher->lastName        = $data['lastName'];
        $teacher->email           = $data['email'];
        $teacher->password        = Hash::make($tempPassword);
        $teacher->department      = $data['department'];
        $teacher->forteSubjectCode = $data['forteSubjectCode'] ?? null;
        $teacher->homeAssignments = $this->normalizeAssignments($data['homeAssignments'] ?? []);
        $teacher->visitingAssignments = $this->normalizeAssignments($data['visitingAssignments'] ?? []);
        $teacher->status          = 'active';
        $teacher->save();

        $emailSent = $this->sendCredentials(
            trim("{$teacher->firstName} {$teacher->lastName}"),
            $teacher->email,
            $tempPassword
        );

        return response()->json([
            'message'   => 'Teacher created.',
            'teacherId' => (string) $teacher->_id,
            'emailSent' => $emailSent,
        ], 201);
    }

    // ------------------------------------------------------------------
    // GET /api/teachers/{id}
    // ------------------------------------------------------------------
    public function show($id)
    {
        $teacher = Teacher::find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        $fullName = trim(($teacher->firstName ?? '') . ' ' . ($teacher->middleName ?? '') . ' ' . ($teacher->lastName ?? ''));
        $fullName = preg_replace('/\s+/', ' ', $fullName);

        return response()->json([
            'data' => [
                'id'                  => (string) $teacher->_id,
                'firstName'           => $teacher->firstName,
                'middleName'          => $teacher->middleName,
                'lastName'            => $teacher->lastName,
                'fullName'            => $fullName,
                'email'               => $teacher->email,
                'department'          => $teacher->department,
                'forteSubjectCode'    => $teacher->forteSubjectCode,
                'homeAssignments'     => $teacher->homeAssignments ?? [],
                'visitingAssignments' => $teacher->visitingAssignments ?? [],
                'status'              => $teacher->status ?? 'active',
                'createdAt'           => $teacher->created_at,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // PATCH /api/teachers/{id}
    // ------------------------------------------------------------------
    public function update(Request $request, $id)
    {
        $teacher = Teacher::find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        $validator = $this->makeValidator($request, $id);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the teacher details and try again.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $teacher->firstName        = $data['firstName'];
        $teacher->middleName       = $data['middleName'] ?? '';
        $teacher->lastName         = $data['lastName'];
        $teacher->email            = $data['email'];
        $teacher->department       = $data['department'];
        $teacher->forteSubjectCode = $data['forteSubjectCode'] ?? null;
        $teacher->homeAssignments  = $this->normalizeAssignments($data['homeAssignments'] ?? []);
        $teacher->visitingAssignments = $this->normalizeAssignments($data['visitingAssignments'] ?? []);
        $teacher->save();

        return response()->json(['message' => 'Teacher updated.']);
    }

    // ------------------------------------------------------------------
    // POST /api/teachers/{id}/delete  (soft delete)
    // ------------------------------------------------------------------
    public function softDelete($id)
    {
        $teacher = Teacher::find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }
        $teacher->status = 'deleted';
        $teacher->save();

        return response()->json(['message' => 'Teacher moved to Deleted Teachers.']);
    }

    // ------------------------------------------------------------------
    // POST /api/teachers/{id}/restore
    // ------------------------------------------------------------------
    public function restore($id)
    {
        $teacher = Teacher::find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }
        $teacher->status = 'active';
        $teacher->save();

        return response()->json(['message' => 'Teacher restored successfully.']);
    }

    // ------------------------------------------------------------------
    // POST /api/teachers/{id}/resend-credentials
    // ------------------------------------------------------------------
    public function resendCredentials($id)
    {
        $teacher = Teacher::find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        $tempPassword = $this->generateTempPassword();
        $teacher->password = Hash::make($tempPassword);
        $teacher->save();

        $emailSent = $this->sendCredentials(
            trim("{$teacher->firstName} {$teacher->lastName}"),
            $teacher->email,
            $tempPassword
        );

        if (!$emailSent) {
            return response()->json([
                'message'   => 'Password was reset, but the email could not be sent. Please check the logs and try again.',
                'emailSent' => false,
            ], 500);
        }

        return response()->json([
            'message'   => 'Login information has been resent.',
            'emailSent' => true,
        ]);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeValidator(Request $request, ?string $ignoreId = null)
    {
        $subjectCodes = implode(',', array_keys(config('school.subjects')));
        $gradeLevels  = implode(',', config('school.grade_levels'));
        $sections     = implode(',', self::SECTION_OPTIONS);

        $emailRule = 'required|email';
        if ($ignoreId) {
            $emailRule .= '|unique:teachers,email,' . $ignoreId . ',_id';
        } else {
            $emailRule .= '|unique:teachers,email';
        }

        $validator = Validator::make($request->all(), [
            'firstName'        => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'middleName'       => ['nullable', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'lastName'         => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'email'            => $emailRule,
            'department'       => ['required', 'in:elementary,preschool'],
            'forteSubjectCode' => ['nullable', 'string', "in:{$subjectCodes}"],

            'homeAssignments'                  => ['array'],
            'homeAssignments.*.gradeLevel'     => ['required', 'string', "in:{$gradeLevels}"],
            'homeAssignments.*.section'        => ['required', 'string', "in:{$sections}"],

            'visitingAssignments'              => ['array'],
            'visitingAssignments.*.gradeLevel' => ['required', 'string', "in:{$gradeLevels}"],
            'visitingAssignments.*.section'    => ['required', 'string', "in:{$sections}"],
        ], [
            'firstName.regex' => 'First name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'middleName.regex' => 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'lastName.regex'  => 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
        ]);

        // Custom rules layered on top of the base ones.
        $validator->after(function ($v) use ($request) {
            $data = $v->getData();

            $department = $data['department'] ?? null;
            $forte      = $data['forteSubjectCode'] ?? null;
            $home       = $data['homeAssignments'] ?? [];
            $visiting   = $data['visitingAssignments'] ?? [];

            // 1. Preschool: no forte, no visiting.
            if ($department === 'preschool') {
                if (!empty($forte)) {
                    $v->errors()->add('forteSubjectCode', 'Preschool teachers do not have a forte subject.');
                }
                if (!empty($visiting)) {
                    $v->errors()->add('visitingAssignments', 'Preschool teachers do not have visiting classes.');
                }
            }

            // 2. Elementary + visiting requires a forte.
            if ($department === 'elementary' && !empty($visiting) && empty($forte)) {
                $v->errors()->add('forteSubjectCode', 'Please select a forte subject before adding visiting classes.');
            }

            // 3. Forte must be offered at each visiting grade.
            if ($department === 'elementary' && !empty($forte) && !empty($visiting)) {
                foreach ($visiting as $i => $row) {
                    $grade = $row['gradeLevel'] ?? null;
                    if ($grade && !GradeSubjectService::isSubjectOfferedAtGrade($forte, $grade)) {
                        $v->errors()->add(
                            "visitingAssignments.{$i}.gradeLevel",
                            "{$forte} is not offered at {$grade}."
                        );
                    }
                }
            }

            // 4. No duplicate pairs within each list.
            foreach (['homeAssignments', 'visitingAssignments'] as $field) {
                $rows = $data[$field] ?? [];
                $seen = [];
                foreach ($rows as $i => $row) {
                    $key = ($row['gradeLevel'] ?? '') . '|' . ($row['section'] ?? '');
                    if ($key === '|') {
                        continue;
                    }
                    if (isset($seen[$key])) {
                        $v->errors()->add("{$field}.{$i}.gradeLevel", 'This class has already been added.');
                    }
                    $seen[$key] = true;
                }
            }
        });

        return $validator;
    }

    private function normalizeAssignments(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $grade = $row['gradeLevel'] ?? null;
            $section = $row['section'] ?? null;
            if (!$grade || !$section) {
                continue;
            }
            $out[] = ['gradeLevel' => $grade, 'section' => $section];
        }
        return $out;
    }

    private function generateTempPassword(): string
    {
        return substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 10);
    }

    private function sendCredentials(string $fullName, string $email, string $password): bool
    {
        try {
            Mail::to($email)->send(new TeacherAccountCreated($fullName, $email, $password));
            return true;
        } catch (\Throwable $e) {
            Log::error("Teacher credentials email failed for {$email}: " . $e->getMessage());
            // Fall back to the log so the admin can retrieve the password
            // if the mail server is down — same posture as the parent flow.
            try {
                $line = sprintf(
                    "[%s] local.ERROR: TeacherAccountCreated failed TO=%s TEMP_PASSWORD=%s\n",
                    now()->toDateTimeString(),
                    $email,
                    $password
                );
                @file_put_contents(storage_path('logs/laravel.log'), $line, FILE_APPEND | LOCK_EX);
            } catch (\Throwable $_) {
            }
            return false;
        }
    }
}