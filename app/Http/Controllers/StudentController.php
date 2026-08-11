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
            'student.firstName' => 'required|string',
            'student.lastName' => 'required|string',
            'student.dateOfBirth' => 'required|date',
            'student.gradeLevel' => 'required|string',
            'student.section' => 'required|string',
            'parent.mode' => 'required|in:new,existing',
            'rfidTag' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the enrollment details and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();

        // ---- Step A: Resolve the parent (either link existing, or create new) ----
        $newParentPassword = null; // will hold the temp password only if a new Firebase account was created

        if ($data['parent']['mode'] === 'existing') {
            $parent = ParentAccount::find($data['parent']['existingParentId']);

            if (!$parent) {
                return response()->json([
                    'message' => 'The selected parent/guardian could not be found. Please search again.',
                ], 422);
            }
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

            // Create the Firebase Auth account for the mobile app login.
            // Wrapped so a Firebase hiccup doesn't block the whole enrollment.
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
                // Leave firebaseUid as null — admin can retry later from the Students page.
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
            $query->where('status', $status);
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
                'hasParentLink' => !empty($student->parentAccountId),
                'status'        => $student->status ?? 'active',
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
}