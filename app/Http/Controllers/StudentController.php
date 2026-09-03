<?php
// app/Http/Controllers/StudentController.php
namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\ParentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\FirebaseService;
use App\Services\FirebaseRealtimeService;
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
                // Reject impossible calendar dates (e.g. Nov 31) that Carbon would
                // otherwise silently roll over into the next valid date instead of
                // rejecting outright.
                $parts = explode('-', $value);
                if (count($parts) === 3 && !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    $fail('Please enter a valid date.');
                    return;
                }

                $age = \Carbon\Carbon::parse($value)->age;
                if ($age < 3 || $age > 15) {
                    $fail('Student age must be between 3 and 15 years old.');
                }
            }],
            'student.gradeLevel' => 'required|string',
            'student.section' => 'required|string',
            'parent.mode' => 'required|in:new,existing',
            'rfidTag' => 'nullable|string',

            'student.isTransferee' => 'nullable|boolean',
            'student.previousSchool' => ['nullable', 'string', 'max:150', function ($attribute, $value, $fail) use ($request) {
                if ($request->input('student.isTransferee') && empty(trim((string) $value))) {
                    $fail('Please enter the name of the student\'s previous school.');
                }
            }],
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
        $confirmParentMismatch = $request->boolean('confirmParentMismatch', false);

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

        // ---- Resolve "existing parent by email" ONCE, reused below in both the
        //      student-duplicate same-parent check and the parent-mismatch check. ----
        $existingByEmail = null;
        if ($data['parent']['mode'] === 'new') {
            $existingByEmail = ParentAccount::where('email', $data['parent']['email'])->first();
        }

        // ---- Step A0: Check for a likely-duplicate student (same name + DOB) ----
        if (!$confirmDuplicate) {
            $candidateMatch = Student::where('dateOfBirth', $data['student']['dateOfBirth'])
                ->get()
                ->first(function ($s) use ($data) {
                    return strtolower(trim($s->firstName)) === strtolower(trim($data['student']['firstName']))
                        && strtolower(trim($s->lastName)) === strtolower(trim($data['student']['lastName']));
                });

            if ($candidateMatch) {
                $prospectiveParentId = null;

                if ($data['parent']['mode'] === 'existing') {
                    $prospectiveParentId = $data['parent']['existingParentId'];
                } elseif ($existingByEmail) {
                    $prospectiveParentId = (string) $existingByEmail->_id;
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

        // ---- Step A0.5: Check for a likely-mistyped email (existing account, different name) ----
        // Two parents never legitimately share one email, so an email match with a
        // DIFFERENT name is far more likely a typo (wrong email entered) than a
        // second guardian coincidentally reusing someone else's inbox. Block the
        // silent auto-link and make staff confirm explicitly.
        if ($data['parent']['mode'] === 'new' && $existingByEmail && !$confirmParentMismatch) {
            $typedNameMatches =
                strtolower(trim($existingByEmail->firstName)) === strtolower(trim($data['parent']['firstName']))
                && strtolower(trim($existingByEmail->lastName)) === strtolower(trim($data['parent']['lastName']));

            if (!$typedNameMatches) {
                return response()->json([
                    'duplicateParent' => true,
                    'message' => "This email is already registered under a different name ({$existingByEmail->firstName} {$existingByEmail->lastName}). Please confirm this is the same person, or check the email address for a typo.",
                    'existingParent' => [
                        'id' => (string) $existingByEmail->_id,
                        'fullName' => trim($existingByEmail->firstName . ' ' . $existingByEmail->lastName),
                        'email' => $existingByEmail->email,
                        'phone' => $existingByEmail->phone,
                    ],
                ], 409);
            }
        }

        // ---- Step A: Resolve the parent (link existing, auto-link by email, or create new) ----
        $newParentPassword = null;
        $parentLinkedExisting = false;

        if ($data['parent']['mode'] === 'existing') {
            $parent = ParentAccount::find($data['parent']['existingParentId']);

            if (!$parent) {
                return response()->json([
                    'message' => 'The selected parent/guardian could not be found. Please search again.',
                ], 422);
            }
        } else {
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

        if (!empty($data['rfidTag'])) {
            $rfidConflict = Student::where('rfidTag', trim($data['rfidTag']))->first();

            if ($rfidConflict) {
                return response()->json([
                    'message' => "This RFID tag is already assigned to {$rfidConflict->firstName} {$rfidConflict->lastName} ({$rfidConflict->studentId}).",
                ], 422);
            }
        }

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
        $student->documents = $data['documents'] ?? [];
        $student->isTransferee = $data['student']['isTransferee'] ?? false;
        $student->previousSchool = $data['student']['previousSchool'] ?? null;
        $student->save();

        // ---- Step D: Link the student to the parent's studentIds array ----
        $existingIds = $parent->studentIds ?? [];
        $existingIds[] = (string) $student->_id;
        $parent->studentIds = $existingIds;
        $parent->save();

        // ---- Mirror the new student + parent to Firebase RTDB for the Flutter
        // parent app. Wrapped in try/catch so a Firebase hiccup never blocks a
        // successful enrollment — Mongo is still the source of truth. ----
        try {
            $realtime = app(FirebaseRealtimeService::class);
            $realtime->mirrorStudent($student);
            $realtime->mirrorParent($parent);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after enrollment for student {$student->studentId}: " . $e->getMessage());
        }

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
        $rfidStatus = $request->query('rfid_status');
        $parentStatus = $request->query('parent_status');
        $sortBy  = in_array($request->query('sort_by', $request->query('sort')), ['name', 'studentId'], true)
            ? $request->query('sort_by', $request->query('sort'))
            : null;
        $sortDir = strtolower((string) $request->query('sort_dir', $request->query('direction', 'asc'))) === 'desc' ? 'desc' : 'asc';
        $currentPage = max(1, (int) $request->query('page', 1));

        $query = Student::query();

        if ($search !== '') {
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            if (count($tokens) > 0) {
                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $tok) {
                        if ($tok === '') {
                            continue;
                        }
                        $q->where(function ($inner) use ($tok) {
                            $inner->where('firstName', 'like', "%{$tok}%")
                                  ->orWhere('lastName', 'like', "%{$tok}%")
                                  ->orWhere('studentId', 'like', "%{$tok}%");
                        });
                    }
                });
            }
        }

        if ($grade) {
            $query->where('gradeLevel', $grade);
        }

        if ($section) {
            $query->where('section', $section);
        }

        if ($status) {
            $statusValues = array_values(array_filter(array_map('trim', explode(',', $status)), 'strlen'));
            if (count($statusValues) > 1) {
                $query->whereIn('enrollmentStatus', $statusValues);
            } else {
                $query->where('enrollmentStatus', $status);
            }
        } else {
            $query->whereIn('enrollmentStatus', ['active', 'inactive']);
        }

        if ($rfidStatus === 'missing') {
            $query->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('rfidTag');
                })->orWhere(function ($sub) {
                    $sub->where('rfidTag', '');
                });
            });
        } elseif ($rfidStatus === 'assigned') {
            $query->whereNotNull('rfidTag')->where('rfidTag', '!=', '');
        }

        if ($parentStatus === 'missing') {
            $query->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('parentId');
                })->orWhere(function ($sub) {
                    $sub->where('parentId', '');
                });
            });
        } elseif ($parentStatus === 'assigned') {
            $query->whereNotNull('parentId')->where('parentId', '!=', '');
        }

        if ($sortBy) {
            $array = $query->get()->all();

            usort($array, function ($a, $b) use ($sortBy, $sortDir) {
                if ($sortBy === 'studentId') {
                    $aId = (string) ($a->studentId ?? '');
                    $bId = (string) ($b->studentId ?? '');
                    $aNum = preg_match('/^(\d+)(.*)$/', $aId, $aM);
                    $bNum = preg_match('/^(\d+)(.*)$/', $bId, $bM);
                    if ($aNum && $bNum) {
                        $cmp = ((int) $aM[1]) <=> ((int) $bM[1]);
                        if ($cmp === 0) {
                            $cmp = strcasecmp((string) $aM[2], (string) $bM[2]);
                        }
                    } else {
                        $cmp = strcasecmp($aId, $bId);
                    }
                } else {
                    $aFull = trim((string) ($a->firstName ?? '') . ' ' . (string) ($a->middleName ?? '') . ' ' . (string) ($a->lastName ?? ''));
                    $bFull = trim((string) ($b->firstName ?? '') . ' ' . (string) ($b->middleName ?? '') . ' ' . (string) ($b->lastName ?? ''));
                    // Primary sort: the displayed full name (FirstName [Middle] LastName) case-insensitive
                    $cmp = strcasecmp($aFull, $bFull);
                    if ($cmp === 0) {
                        // School-roster tiebreak: LastName → FirstName → MiddleName
                        $cmp = strcasecmp((string) ($a->lastName ?? ''), (string) ($b->lastName ?? ''));
                        if ($cmp === 0) {
                            $cmp = strcasecmp((string) ($a->firstName ?? ''), (string) ($b->firstName ?? ''));
                        }
                        if ($cmp === 0) {
                            $cmp = strcasecmp((string) ($a->middleName ?? ''), (string) ($b->middleName ?? ''));
                        }
                    }
                }
                if ($cmp === 0) {
                    // Stable fallback: newest first when values tie (mirrors Attendance sort)
                    $aTs = (string) (isset($a->created_at) ? $a->created_at : '');
                    $bTs = (string) (isset($b->created_at) ? $b->created_at : '');
                    return strcmp($bTs, $aTs);
                }
                return $sortDir === 'desc' ? -$cmp : $cmp;
            });

            $total = count($array);
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($currentPage > $lastPage) {
                $currentPage = $lastPage;
            }
            $offset = ($currentPage - 1) * $perPage;
            $pageSlice = array_slice($array, $offset, $perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = $total > 0 ? min($offset + $perPage, $total) : 0;
            $items = collect($pageSlice);
        } else {
            $total = $query->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($currentPage > $lastPage) {
                $currentPage = $lastPage;
            }
            $offset = ($currentPage - 1) * $perPage;
            $items = $query
                ->orderBy('created_at', 'desc')
                ->orderBy('_id', 'desc')
                ->skip($offset)
                ->take($perPage)
                ->get();
            $from = $total > 0 ? $offset + 1 : 0;
            $to = $total > 0 ? min($offset + $perPage, $total) : 0;
        }

        // Shape the response so the frontend doesn't need to know Mongo internals
        $data = collect($items)->map(function ($student) {
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

        $perPage     = $perPage;
        $from        = $from;
        $to          = $to;

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $currentPage,
                'currentPage'  => $currentPage,
                'from'         => $from,
                'to'           => $to,
                'total'        => $total,
                'per_page'     => $perPage,
                'perPage'      => $perPage,
                'last_page'    => $lastPage,
                'lastPage'     => $lastPage,
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
                'documents'      => $student->documents ?? [],
                'isTransferee'   => $student->isTransferee ?? false,  
                'previousSchool' => $student->previousSchool ?? null,
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

        $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);
        $studentFullName = trim($student->firstName . ' ' . $student->lastName);
        $tempPassword = null;
        $firebaseOutcome = null;

        try {
            $firebase = app(FirebaseService::class);

            try {
                $existing = $firebase->getAuth()->getUserByEmail($parent->email);
                $tempPassword = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 10);
                $firebase->getAuth()->changeUserPassword($existing->uid, $tempPassword);
                $firebaseOutcome = ['uid' => $existing->uid, 'password' => $tempPassword];
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                $recreate = $firebase->createParentAccount($parent->email, $parentFullName);
                if (empty($recreate['password'])) {
                    throw new \RuntimeException('Could not generate a new password for this parent.');
                }
                if (!empty($recreate['uid']) && empty($parent->firebaseUid)) {
                    $parent->firebaseUid = $recreate['uid'];
                    $parent->save();
                }
                $firebaseOutcome = $recreate;
                $tempPassword = (string) $recreate['password'];
            }
        } catch (\Throwable $e) {
            $msg = "Resend credentials (Firebase) failed for {$parent->email}: " . $e->getMessage();
            try {
                \Illuminate\Support\Facades\Log::error($msg . ' :: ' . $e->getTraceAsString());
            } catch (\Throwable $_) {}
            error_log('[KidSecure] ' . $msg);

            $fallbackPw = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 10);
            $tempPassword = $fallbackPw;
            $firebaseOutcome = ['uid' => null, 'password' => $tempPassword, 'fallback' => true];
        }

        try {
            $mailable = new ParentAccountCreated(
                $parentFullName,
                (string) $parent->email,
                (string) $tempPassword,
                (string) $studentFullName
            );

            Mail::to((string) $parent->email)->send($mailable);
        } catch (\Throwable $e) {
            $msg = "Resend credentials (Mail) failed for {$parent->email}: " . $e->getMessage();
            try {
                \Illuminate\Support\Facades\Log::error($msg . ' :: ' . $e->getTraceAsString());
            } catch (\Throwable $_) {}
            error_log('[KidSecure] ' . $msg);

            $logPath = storage_path('logs/laravel.log');
            try {
                $line = sprintf(
                    "[%s] local.ERROR: %s :: TEMP_PASSWORD=%s TO=%s PARENT=%s STUDENT=%s\n",
                    now()->toDateTimeString(),
                    $msg,
                    (string) $tempPassword,
                    (string) $parent->email,
                    (string) $parentFullName,
                    (string) $studentFullName
                );
                $dir = dirname($logPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
            } catch (\Throwable $_) {}

            return response()->json([
                'message' => 'Unable to resend login information. Please try again.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }

        $logPath = storage_path('logs/laravel.log');
        try {
            $line = sprintf(
                "[%s] local.INFO: Parent credentials resent (via student) TO=%s PARENT=%s STUDENT=%s TEMP_PASSWORD=%s OUTCOME=%s\n",
                now()->toDateTimeString(),
                (string) $parent->email,
                (string) $parentFullName,
                (string) $studentFullName,
                (string) $tempPassword,
                !empty($firebaseOutcome['fallback']) ? 'fallback' : 'firebase'
            );
            $dir = dirname($logPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $_) {}

        return response()->json(['message' => 'Login information has been resent.']);
    }

    public function deactivate($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->enrollmentStatus = 'inactive';
        $student->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after deactivate for student {$student->studentId}: " . $e->getMessage());
        }

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
                // Reject impossible calendar dates (e.g. Nov 31) that Carbon would
                // otherwise silently roll over into the next valid date instead of
                // rejecting outright.
                $parts = explode('-', $value);
                if (count($parts) === 3 && !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    $fail('Please enter a valid date.');
                    return;
                }

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

        try {
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after update for student {$student->studentId}: " . $e->getMessage());
        }

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

        try {
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after reactivate for student {$student->studentId}: " . $e->getMessage());
        }

        return response()->json(['message' => 'Student reactivated.']);
    }

    /**
     * POST /api/students/{id}/delete
     * Soft-deletes a student by marking enrollmentStatus = 'deleted'.
     * Records are kept and can be restored later via POST /restore.
     */
    public function softDelete($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->enrollmentStatus = 'deleted';
        $student->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after soft-delete for student {$student->studentId}: " . $e->getMessage());
        }

        return response()->json(['message' => 'Student moved to Deleted Students.']);
    }

    /**
     * POST /api/students/{id}/restore
     * Restores a soft-deleted student back to 'active' status.
     */
    public function restore($id)
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->enrollmentStatus = 'active';
        $student->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after restore for student {$student->studentId}: " . $e->getMessage());
        }

        return response()->json(['message' => 'Student restored successfully.']);
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

        // rfidTag is intentionally NOT mirrored to RTDB — the parent app
        // has no reason to know the physical tag value.

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
        $oldParent = null;

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

        // Both parents' linked-student lists changed, so both need remirroring.
        try {
            $realtime = app(FirebaseRealtimeService::class);
            if ($oldParent) {
                $realtime->mirrorParent($oldParent);
            }
            $realtime->mirrorParent($newParent);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after parent reassignment for student {$student->studentId}: " . $e->getMessage());
        }

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