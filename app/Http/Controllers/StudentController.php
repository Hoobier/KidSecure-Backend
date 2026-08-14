<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\ParentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\FirebaseService;
use App\Mail\ParentAccountCreated;
use Illuminate\Support\Facades\Mail;

class StudentController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student.firstName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'student.middleName' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') return;
                if (!preg_match('/^[A-Za-z\s\-\'.]{2,50}$/', $value)) {
                    $fail('Middle name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).');
                }
            }],
            'student.lastName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'student.dateOfBirth' => ['required', 'date', 'before:today', function ($attribute, $value, $fail) {
                $age = \Carbon\Carbon::parse($value)->age;
                if ($age < 3 || $age > 15) {
                    $fail('Student age must be between 3 and 15 years old.');
                }
            }],
            'student.gradeLevel' => 'required|string',
            'student.section' => 'required|string',
            'parent.mode' => 'required|in:new,existing',
            'rfidTag' => 'nullable|string',
        ], [
            'student.firstName.regex' => 'First name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'student.lastName.regex' => 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'student.dateOfBirth.before' => 'Date of birth cannot be in the future.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the enrollment details and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        $confirmDuplicate = $request->boolean('confirmDuplicate', false);

        // ---- Parent-specific validation, only for the fields that mode actually requires ----
        if ($data['parent']['mode'] === 'new') {
            $parentValidator = Validator::make($data['parent'], [
                'firstName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
                'lastName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
                'email' => ['required', 'email'],
                'phone' => ['required', 'regex:/^09\d{9}$/'],
            ], [
                'firstName.regex' => 'Parent first name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
                'lastName.regex' => 'Parent last name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
                'phone.regex' => 'Please enter a valid 11-digit Philippine mobile number (e.g. 09171234567).',
            ]);

            if ($parentValidator->fails()) {
                return response()->json([
                    'message' => 'Please check the parent/guardian details and try again.',
                    'errors' => $parentValidator->errors(),
                ], 422);
            }
        } else {
            if (empty($data['parent']['existingParentId'])) {
                return response()->json([
                    'message' => 'Please search for and select a parent/guardian.',
                ], 422);
            }
        }

        // ---- Step A0: Check for a likely-duplicate student (same name + DOB) ----
        // Case-insensitive comparison done in PHP after narrowing by dateOfBirth,
        // since exact-match Mongo queries are case-sensitive and staff may type
        // names with different casing than what's already on file.
        // ---- Step A0: Check for a likely-duplicate student (same name + DOB) ----
        if (!$confirmDuplicate) {
            $candidateMatch = Student::where('dateOfBirth', $data['student']['dateOfBirth'])
                ->get()
                ->first(function ($s) use ($data) {
                    return strtolower(trim($s->firstName)) === strtolower(trim($data['student']['firstName']))
                        && strtolower(trim($s->lastName)) === strtolower(trim($data['student']['lastName']));
                });

            if ($candidateMatch) {
                // Determine whether this enrollment would resolve to the SAME parent
                // as the existing match — that's the signal that separates a likely
                // coincidence (e.g. twins in different families) from a near-certain
                // duplicate entry (same kid, same guardian, entered twice).
                $prospectiveParentId = null;

                if ($data['parent']['mode'] === 'existing') {
                    $prospectiveParentId = $data['parent']['existingParentId'];
                } else {
                    $existingByEmail = ParentAccount::where('email', $data['parent']['email'])->first();
                    if ($existingByEmail) {
                        $prospectiveParentId = (string) $existingByEmail->_id;
                    }
                }

                $samesParent = $prospectiveParentId && $prospectiveParentId === $candidateMatch->parentId;

                return response()->json([
                    'duplicate' => true,
                    'sameParent' => $samesParent,
                    'message' => $samesParent
                        ? 'This student appears to already be enrolled under the same parent/guardian account. This is very likely a duplicate entry.'
                        : 'A student with this name and date of birth is already enrolled.',
                    'existingStudent' => [
                        'id' => (string) $candidateMatch->_id,
                        'studentId' => $candidateMatch->studentId,
                        'fullName' => trim($candidateMatch->firstName . ' ' . $candidateMatch->lastName),
                        'gradeLevel' => $candidateMatch->gradeLevel,
                        'section' => $candidateMatch->section,
                        'status' => $candidateMatch->enrollmentStatus ?? 'active',
                    ],
                ], 409);
            }
        }

        // ---- Step A: Resolve the parent (link existing, auto-link by email, or create new) ----
        $newParentPassword = null;
        $parentLinkedExisting = false; // for the success message, if a "new" submission got auto-linked instead

        if ($data['parent']['mode'] === 'existing') {
            $parent = ParentAccount::find($data['parent']['existingParentId']);

            if (!$parent) {
                return response()->json([
                    'message' => 'The selected parent/guardian could not be found. Please search again.',
                ], 422);
            }
        } else {
            // "new" mode — but first check if a parent with this exact email already exists.
            // Two parents never legitimately share one email, so auto-link rather than
            // creating a second ParentAccount row for the same person.
            $existingByEmail = ParentAccount::where('email', $data['parent']['email'])->first();

            if ($existingByEmail) {
                $parent = $existingByEmail;
                $parentLinkedExisting = true;
            } else {
                $parent = new ParentAccount();
                $parent->firstName = $data['parent']['firstName'];
                $parent->lastName = $data['parent']['lastName'];
                $parent->email = $data['parent']['email'];
                $parent->phone = $data['parent']['phone'];
                $parent->firebaseUid = null;
                $parent->studentIds = [];
                $parent->accountCreatedAt = now();
                $parent->save();

                $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);

                try {
                    $firebase = app(FirebaseService::class);
                    $result = $firebase->createParentAccount($parent->email, $parentFullName);

                    $parent->firebaseUid = $result['uid'];
                    $parent->save();

                    if (!$result['reused']) {
                        $newParentPassword = $result['password'];
                    }
                } catch (\Throwable $e) {
                    \Log::error("Firebase account creation failed for {$parent->email}: " . $e->getMessage());
                }
            }
        }

        // ---- Step B: Generate the human-readable Student ID (YYYY-####) ----
        $year = now()->year;
        $countThisYear = Student::where('studentId', 'like', "{$year}-%")->count();
        $nextNumber = str_pad($countThisYear + 1, 4, '0', STR_PAD_LEFT);
        $studentId = "{$year}-{$nextNumber}";

        // ---- Step C: Create the student record ----
        $student = new Student();
        $student->studentId = $studentId;
        $student->firstName = $data['student']['firstName'];
        $student->middleName = $data['student']['middleName'] ?? '';
        $student->lastName = $data['student']['lastName'];
        $student->dateOfBirth = $data['student']['dateOfBirth'];
        $student->gradeLevel = $data['student']['gradeLevel'];
        $student->section = $data['student']['section'];
        $student->rfidTag = $data['rfidTag'] ?: null;
        $student->parentId = (string) $parent->_id;
        $student->enrollmentStatus = 'active';
        $student->dateEnrolled = now();
        $student->save();

        // ---- Step D: Link the student to the parent's studentIds array ----
        $existingIds = $parent->studentIds ?? [];
        $existingIds[] = (string) $student->_id;
        $parent->studentIds = $existingIds;
        $parent->save();

        // ---- Step E: Send the parent their login credentials, if a new account was made ----
        if ($newParentPassword) {
            $studentFullName = trim($student->firstName . ' ' . $student->lastName);
            $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);

            try {
                Mail::to($parent->email)->send(
                    new ParentAccountCreated($parentFullName, $parent->email, $newParentPassword, $studentFullName)
                );
            } catch (\Throwable $e) {
                \Log::error("Failed to send parent credentials email to {$parent->email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Enrollment successful.',
            'studentId' => $studentId,
            'parentLinkedExisting' => $parentLinkedExisting,
        ], 201);
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $search  = trim((string) $request->query('search', ''));
        $grade   = $request->query('grade');
        $section = $request->query('section');
        $status  = $request->query('status');

        $query = Student::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('firstName', 'like', "%{$search}%")
                ->orWhere('lastName', 'like', "%{$search}%")
                ->orWhere('studentId', 'like', "%{$search}%");
            });
        }

        if ($grade) {
            $query->where('gradeLevel', $grade);
        }

        if ($section) {
            $query->where('section', $section);
        }

        if ($status) {
            $query->where('enrollmentStatus', $status);
        }

        $query->orderBy('lastName')->orderBy('firstName');

        $students = $query->paginate($perPage);

        // Shape the response so the frontend doesn't need to know Mongo internals
        $data = collect($students->items())->map(function ($student) {
            return [
                'id'            => $student->_id,
                'studentId'     => $student->studentId,
                'fullName'      => trim("{$student->firstName} {$student->lastName}"),
                'gradeLevel'    => $student->gradeLevel,
                'section'       => $student->section,
                'hasRfidTag'    => !empty($student->rfidTag),
                'hasParentLink' => !empty($student->parentId),
                'status'        => $student->enrollmentStatus ?? 'active',
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $students->currentPage(),
                'perPage'     => $students->perPage(),
                'total'       => $students->total(),
                'lastPage'    => $students->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $parent = null;
        if ($student->parentId) {
            $parent = ParentAccount::find($student->parentId);
        }

        return response()->json([
            'data' => [
                'id'          => $student->_id,
                'studentId'   => $student->studentId,
                'firstName'   => $student->firstName,
                'middleName'  => $student->middleName,
                'lastName'    => $student->lastName,
                'dateOfBirth' => $student->dateOfBirth,
                'gradeLevel'  => $student->gradeLevel,
                'section'     => $student->section,
                'status'      => $student->enrollmentStatus ?? 'active',
                'enrolledAt'  => $student->created_at,
                'rfidTag'     => $student->rfidTag,
                'parent'      => $parent ? [
                    'id'          => $parent->_id,
                    'fullName'    => trim("{$parent->firstName} {$parent->lastName}"),
                    'email'       => $parent->email,
                    'phone' => $parent->phone,
                ] : null,
            ],
        ]);
    }

    public function resendCredentials($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if (!$student->parentId) {
            return response()->json(['message' => 'This student has no linked parent account.'], 422);
        }

        $parent = ParentAccount::find($student->parentId);

        if (!$parent) {
            return response()->json(['message' => 'Linked parent account could not be found.'], 404);
        }

        try {
            $firebase = app(FirebaseService::class);
            $result = $firebase->resetParentPassword($parent->email);
            // Expecting $result to return a fresh temporary password, e.g. ['password' => '...']

            $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);
            $studentFullName = trim($student->firstName . ' ' . $student->lastName);

            Mail::to($parent->email)->send(
                new ParentAccountCreated($parentFullName, $parent->email, $result['password'], $studentFullName)
            );

            return response()->json(['message' => 'Login information has been resent.']);
        } catch (\Throwable $e) {
            \Log::error("Resend credentials failed for {$parent->email}: " . $e->getMessage());
            return response()->json([
                'message' => 'Unable to resend login information. Please try again.',
            ], 500);
        }
    }

    public function deactivate($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->enrollmentStatus = 'inactive';
        $student->save();

        return response()->json(['message' => 'Student has been deactivated.']);
    }

    public function update(Request $request, $id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'middleName' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') return;
                if (!preg_match('/^[A-Za-z\s\-\'.]{2,50}$/', $value)) {
                    $fail('Middle name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).');
                }
            }],
            'lastName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'dateOfBirth' => ['required', 'date', 'before:today', function ($attribute, $value, $fail) {
                $age = \Carbon\Carbon::parse($value)->age;
                if ($age < 3 || $age > 15) {
                    $fail('Student age must be between 3 and 15 years old.');
                }
            }],
            'gradeLevel' => 'required|string',
            'section' => 'required|string',
        ], [
            'firstName.regex' => 'First name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'lastName.regex' => 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'dateOfBirth.before' => 'Date of birth cannot be in the future.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the student details and try again.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $request->all();

        $student->firstName   = $data['firstName'];
        $student->middleName  = $data['middleName'] ?? '';
        $student->lastName    = $data['lastName'];
        $student->dateOfBirth = $data['dateOfBirth'];
        $student->gradeLevel  = $data['gradeLevel'];
        $student->section     = $data['section'];
        $student->save();

        return response()->json(['message' => 'Student information updated.']);
    }

    public function reactivate($id)
    {
        $student = Student::find($id);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        $student->enrollmentStatus = 'active';
        $student->save();
        return response()->json(['message' => 'Student reactivated.']);
    }

    /**
     * POST /api/students/{id}/reassign-rfid
     * Updates a student's RFID tag. Enforces uniqueness — no two students
     * should share one physical tag, since the future ESP32 endpoint needs
     * a tag to resolve to exactly one student.
     */
    public function reassignRfid(Request $request, $id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'rfidTag' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the RFID tag and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $newTag = $request->input('rfidTag');
        $newTag = $newTag ? trim($newTag) : null;

        if ($newTag) {
            $conflict = Student::where('rfidTag', $newTag)
                ->where('_id', '!=', $id)
                ->first();

            if ($conflict) {
                return response()->json([
                    'message' => "This RFID tag is already assigned to {$conflict->firstName} {$conflict->lastName} ({$conflict->studentId}).",
                ], 422);
            }
        }

        $student->rfidTag = $newTag;
        $student->save();

        return response()->json(['message' => 'RFID tag updated.', 'rfidTag' => $newTag]);
    }

    /**
     * POST /api/students/{id}/reassign-parent
     * Re-links a student to a different existing parent account.
     * Student.parentId is the canonical link (what Parent Directory and
     * everything else reads from). ParentAccount.studentIds is updated
     * best-effort on both sides for consistency, but is not load-bearing —
     * nothing downstream depends on it being perfectly in sync.
     */
    public function reassignParent(Request $request, $id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'parentId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please select a parent/guardian.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $newParent = ParentAccount::find($request->input('parentId'));

        if (!$newParent) {
            return response()->json(['message' => 'Selected parent/guardian could not be found.'], 422);
        }

        $oldParentId = $student->parentId;

        // Best-effort: remove student from old parent's studentIds, if that parent exists
        // and the array actually contains this student.
        if ($oldParentId && $oldParentId !== (string) $newParent->_id) {
            $oldParent = ParentAccount::find($oldParentId);
            if ($oldParent) {
                $oldParent->studentIds = array_values(array_diff($oldParent->studentIds ?? [], [(string) $student->_id]));
                $oldParent->save();
            }
        }

        // Best-effort: add student to new parent's studentIds, if not already present.
        $newParentStudentIds = $newParent->studentIds ?? [];
        if (!in_array((string) $student->_id, $newParentStudentIds)) {
            $newParentStudentIds[] = (string) $student->_id;
            $newParent->studentIds = $newParentStudentIds;
            $newParent->save();
        }

        $student->parentId = (string) $newParent->_id;
        $student->save();

        return response()->json([
            'message' => 'Parent/guardian reassigned.',
            'parent' => [
                'id' => (string) $newParent->_id,
                'fullName' => trim($newParent->firstName . ' ' . $newParent->lastName),
                'email' => $newParent->email,
                'phone' => $newParent->phone,
            ],
        ]);
    }
}