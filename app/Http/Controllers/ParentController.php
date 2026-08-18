<?php
//ParentController.php
namespace App\Http\Controllers;

use App\Models\ParentAccount;
use App\Models\Student;
use App\Services\FirebaseRealtimeService;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function search(Request $request)
    {
        $query = trim((string) $request->query('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['parents' => []]);
        }

        $tokens = preg_split('/\s+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY);

        $parents = ParentAccount::query();
        if (count($tokens) > 0) {
            $parents = $parents->where(function ($q) use ($tokens) {
                foreach ($tokens as $tok) {
                    if ($tok === '') {
                        continue;
                    }
                    $q = $q->where(function ($inner) use ($tok) {
                        $inner->where('firstName', 'like', "%{$tok}%")
                              ->orWhere('lastName', 'like', "%{$tok}%")
                              ->orWhere('email', 'like', "%{$tok}%");
                    });
                }
            });
        }
        $parents = $parents->limit(10)->get();

        $results = $parents->map(function ($parent) {
            return [
                'id' => (string) $parent->_id,
                'firstName' => $parent->firstName,
                'lastName' => $parent->lastName,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'studentCount' => count($parent->studentIds ?? []),
            ];
        });

        return response()->json(['parents' => $results]);
    }

    /**
     * GET /api/parents
     * Full parent directory listing for the admin portal's Parent Directory page.
     * Children are derived from Student.parentId (canonical link) rather than
     * ParentAccount.studentIds, since studentIds isn't touched by search() above
     * and its sync guarantees aren't confirmed.
     *
     * Pagination: ?page=int  +  ?per_page=int  +  ?search=string
     */
    public function index(Request $request)
    {
        $parents = ParentAccount::all();
        $students = Student::whereIn('enrollmentStatus', ['active', 'inactive', 'deleted'])
            ->get(['studentId', 'firstName', 'lastName', 'parentId', 'enrollmentStatus']);

        $childrenByParent = [];
        foreach ($students as $s) {
            $childrenByParent[$s->parentId][] = [
                'id' => (string) $s->_id,
                'studentId' => $s->studentId,
                'name' => trim($s->firstName . ' ' . $s->lastName),
                'status' => $s->enrollmentStatus ?? 'active',
            ];
        }

        $result = $parents->map(function ($p) use ($childrenByParent) {
            return [
                'id' => (string) $p->_id,
                'name' => trim($p->firstName . ' ' . $p->lastName),
                'email' => $p->email,
                'phone' => $p->phone,
                'children' => $childrenByParent[(string) $p->_id] ?? [],
            ];
        });

        if ($request->filled('search')) {
            $tokens = preg_split('/\s+/', strtolower(trim((string) $request->query('search'))), -1, PREG_SPLIT_NO_EMPTY);
            if (count($tokens) > 0) {
                $result = $result->filter(function ($p) use ($tokens) {
                    $haystacks = [
                        strtolower($p['name'] ?? ''),
                        strtolower($p['email'] ?? ''),
                    ];
                    foreach ($p['children'] ?? [] as $child) {
                        $haystacks[] = strtolower($child['name'] ?? '');
                    }
                    $blob = implode(' ', $haystacks);
                    foreach ($tokens as $tok) {
                        if ($tok === '') {
                            continue;
                        }
                        if (str_contains($blob, $tok) === false) {
                            return false;
                        }
                    }
                    return true;
                })->values();
            }
        }

        // Keep parent list ordered by name for stable pagination between pages
        $result = $result->sortBy(function ($p) {
            return strtolower($p['name']);
        })->values();

        $perPage = max(1, (int) $request->query('per_page', 20));
        $currentPage = max(1, (int) $request->query('page', 1));
        $total = $result->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($currentPage > $lastPage) {
            $currentPage = $lastPage;
        }
        $offset = ($currentPage - 1) * $perPage;
        $from = $total > 0 ? $offset + 1 : 0;
        $to = $total > 0 ? min($offset + $perPage, $total) : 0;

        $pageSlice = $result->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $pageSlice,
            'meta' => [
                'current_page' => $currentPage,
                'from'         => $from,
                'to'           => $to,
                'total'        => $total,
                'per_page'     => $perPage,
                'last_page'    => $lastPage,
            ],
        ]);
    }

    /**
     * POST /api/parents/{id}/resend-credentials
     * Resends mobile app login credentials directly from the parent's own ID,
     * rather than requiring a student ID like StudentController::resendCredentials()
     * does. Mirrors that method's Firebase/email logic.
     */
    public function resendCredentials($id)
    {
        $parent = ParentAccount::find($id);

        if (!$parent) {
            return response()->json(['message' => 'Parent account not found.'], 404);
        }

        $student = Student::where('parentId', (string) $parent->_id)->first();
        $studentFullName = $student ? trim($student->firstName . ' ' . $student->lastName) : '';
        $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);
        $tempPassword = null;
        $firebaseOutcome = null;

        try {
            $firebase = app(\App\Services\FirebaseService::class);

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
            $mailable = new \App\Mail\ParentAccountCreated(
                $parentFullName,
                (string) $parent->email,
                (string) $tempPassword,
                (string) $studentFullName
            );

            \Illuminate\Support\Facades\Mail::to((string) $parent->email)->send($mailable);
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
                "[%s] local.INFO: Parent credentials resent TO=%s PARENT=%s STUDENT=%s TEMP_PASSWORD=%s OUTCOME=%s\n",
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

        return response()->json([
            'message' => 'Login information has been resent.',
        ], 200);
    }

    /**
     * GET /api/parents/{id}
     * Read a single parent account in detail — used by the admin portal's
     * /account/[id] parent detail and /account/[id]/edit pages.
     */
    public function show($id)
    {
        $parent = ParentAccount::find($id);
        if (!$parent) {
            return response()->json(['message' => 'Parent account not found.'], 404);
        }

        $students = Student::whereIn('enrollmentStatus', ['active', 'inactive', 'deleted'])
            ->where('parentId', (string) $parent->_id)
            ->orderBy('created_at', 'desc')
            ->get(['studentId', 'firstName', 'lastName', 'gradeLevel', 'section', 'enrollmentStatus', 'created_at']);

        $children = $students->map(function ($s) {
            return [
                'id'             => (string) $s->_id,
                'studentId'      => $s->studentId,
                'name'           => trim($s->firstName . ' ' . $s->lastName),
                'firstName'      => $s->firstName,
                'lastName'       => $s->lastName,
                'gradeLevel'     => $s->gradeLevel,
                'section'        => $s->section,
                'status'         => $s->enrollmentStatus ?? 'active',
                'enrolledAt'     => $s->created_at,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'id'         => (string) $parent->_id,
                'firstName'  => $parent->firstName,
                'lastName'   => $parent->lastName,
                'name'       => trim($parent->firstName . ' ' . $parent->lastName),
                'email'      => $parent->email,
                'phone'      => $parent->phone,
                'createdAt'  => $parent->created_at,
                'children'   => $children,
            ],
        ]);
    }

    /**
     * PATCH /api/parents/{id}
     * Updates firstName / lastName / email / phone from the admin portal's
     * parent edit page. Validates fields the same way the enrollment flow
     * does for new parents (when creating a ParentAccount from scratch).
     */
    public function update(Request $request, $id)
    {
        $parent = ParentAccount::find($id);
        if (!$parent) {
            return response()->json(['message' => 'Parent account not found.'], 404);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'firstName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'lastName'  => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'email'     => ['required', 'email', function ($attribute, $value, $fail) use ($parent) {
                $duplicate = ParentAccount::where('email', $value)
                    ->where('_id', '!=', (string) $parent->_id)
                    ->first();
                if ($duplicate) {
                    $fail('This email is already in use by another parent account.');
                }
            }],
            'phone'     => ['required', 'string', 'regex:/^09\d{9}$/'],
        ], [
            'firstName.regex' => 'First name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'lastName.regex'  => 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods (2–50 characters).',
            'email.email'     => 'Please enter a valid email address.',
            'phone.regex'     => 'Phone number must start with 09 and be exactly 11 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the parent details and try again.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $parent->firstName = $data['firstName'];
        $parent->lastName  = $data['lastName'];
        $parent->email     = $data['email'];
        $parent->phone     = $data['phone'];
        $parent->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorParent($parent);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after admin-side parent update for parent {$parent->_id}: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Parent information updated.',
            'data'    => [
                'id'        => (string) $parent->_id,
                'firstName' => $parent->firstName,
                'lastName'  => $parent->lastName,
                'email'     => $parent->email,
                'phone'     => $parent->phone,
            ],
        ]);
    }
}