<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentApplication;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EnrollmentApplicationController extends Controller
{
    public function __construct(protected EnrollmentService $enrollmentService)
    {
    }

    /**
     * POST /api/guest/enrollments/{id}/convert-to-student
     * Body: { rfidTag: string, section: string, confirmDuplicate?: bool, confirmParentMismatch?: bool }
     */
    public function convertToStudent(Request $request, $id)
    {
        $application = EnrollmentApplication::find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($application->status !== 'pending') {
            return response()->json([
                'message' => 'This application has already been ' . $application->status . '.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rfidTag' => 'required|string|max:50',
            'section' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please provide a section and a scanned RFID tag before approving.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $studentApp = $application->student ?? [];
        $parentApp = $application->parent ?? [];
        $academicApp = $application->academic ?? [];

        // The guest form's dateOfBirth field is stored as "birthDate" —
        // Student/EnrollmentService both expect "dateOfBirth".
        $studentInput = [
            'firstName' => $studentApp['firstName'] ?? '',
            'middleName' => $studentApp['middleName'] ?? '',
            'lastName' => $studentApp['lastName'] ?? '',
            'dateOfBirth' => $studentApp['birthDate'] ?? '',
            'gradeLevel' => $academicApp['gradeLevel'] ?? '',
            // Guest form never asks for a section — the admin picks one
            // here at approval time.
            'section' => $request->input('section'),
            'isTransferee' => !empty($academicApp['previousSchool']),
            'previousSchool' => $academicApp['previousSchool'] ?? null,
            'documents' => $this->flattenApplicationDocuments($application->documents ?? []),
        ];

        // Validate the application's own data before creating a real student
        // record from it — the guest form itself never enforced age limits
        // or name formatting, only StudentController's HTTP layer did.
        $studentValidator = Validator::make($studentInput, [
            'firstName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'lastName' => ['required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'gradeLevel' => 'required|string',
            'section' => 'required|string',
        ]);

        if ($studentValidator->fails()) {
            return response()->json([
                'message' => 'This application is missing required student details and cannot be converted yet.',
                'errors' => $studentValidator->errors(),
            ], 422);
        }

        // Guest applications never have an "existing parent" search option —
        // the shared service still auto-links by email if a matching
        // ParentAccount already exists, same as the walk-in flow.
        $parentInput = [
            'mode' => 'new',
            'firstName' => $parentApp['firstName'] ?? '',
            'lastName' => $parentApp['lastName'] ?? '',
            'relationship' => $parentApp['relationship'] ?? 'Guardian',
            'email' => $parentApp['email'] ?? '',
            'phone' => $parentApp['phone'] ?? '',
        ];

        $result = $this->enrollmentService->createStudentAndParent($studentInput, $parentInput, [
            'rfidTag' => $request->input('rfidTag'),
            'confirmDuplicate' => $request->boolean('confirmDuplicate', false),
            'confirmParentMismatch' => $request->boolean('confirmParentMismatch', false),
        ]);

        if ($result['outcome'] !== 'success') {
            $status = in_array($result['outcome'], ['duplicate_student', 'duplicate_parent_email']) ? 409 : 422;
            return response()->json($result, $status);
        }

        $application->status = 'converted';
        $application->convertedStudentId = (string) $result['student']->_id;
        $application->save();

        return response()->json([
            'message' => 'Application converted to student.',
            'student_id' => (string) $result['student']->_id,
            'studentId' => $result['studentId'],
        ], 201);
    }

    /**
     * Converts the application's keyed documents ({ birth_certificate: {...} })
     * into the flat array shape Student.documents already uses.
     */
    private function flattenApplicationDocuments(array $documents): array
    {
        $flat = [];
        foreach ($documents as $type => $doc) {
            if (!is_array($doc)) continue;
            $flat[] = array_merge(['type' => $type], $doc);
        }
        return $flat;
    }

    private function firstNameOf(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        return $parts[0] ?? '';
    }

    private function lastNameOf(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    }

    /**
     * POST /api/guest/enrollments (PUBLIC — no auth)
     */
    public function store(Request $request)
    {
        $payload = json_decode($request->input('data', '{}'), true) ?: [];

        $validator = Validator::make($payload, [
            'student.firstName' => 'required|string|max:50',
            'student.lastName' => 'required|string|max:50',
            'student.birthDate' => 'required|date',
            'student.gender' => 'required|string',
            'student.address' => 'required|string',
            'student.phone' => ['required', 'regex:/^09\d{9}$/'],
            'student.email' => 'required|email',
            'parent.firstName' => 'required|string|max:50',
            'parent.lastName' => 'required|string|max:50',
            'parent.relationship' => 'required|string',
            'parent.phone' => ['required', 'regex:/^09\d{9}$/'],
            'parent.email' => 'required|email',
            'academic.gradeLevel' => 'required|string',
            'signature' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check the application details and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $application = EnrollmentApplication::create([
            'student' => $payload['student'],
            'parent' => $payload['parent'],
            'academic' => $payload['academic'] ?? [],
            'signature' => $payload['signature'] ?? null,
            'documents' => [],
            'status' => 'pending',
        ]);

        // Upload documents now that we have the application's own _id to file them under.
        $uploadService = app(\App\Services\DocumentUploadService::class);
        $documents = [];

        foreach (['birth_certificate', 'id_picture_1x1'] as $docType) {
            if ($request->hasFile($docType)) {
                $documents[$docType] = $uploadService->upload(
                    $request->file($docType),
                    (string) $application->_id,
                    $docType,
                    'guest-applications'
                );
            }
        }

        if (!empty($documents)) {
            $application->documents = $documents;
            $application->save();
        }

        return response()->json([
            'message' => 'Application submitted successfully.',
            'referenceNumber' => $application->referenceNumber,
        ], 201);
    }

    /**
     * GET /api/guest/enrollments (Sanctum-guarded)
     */
    /**
     * GET /api/guest/enrollments (Sanctum-guarded)
     * Query params: search, status, page, per_page
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $currentPage = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $query = EnrollmentApplication::query();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $tok) {
                $tokUpper = strtoupper($tok);
                $query->where(function ($q) use ($tok, $tokUpper) {
                    $q->where('student.firstName', 'like', "%{$tok}%")
                    ->orWhere('student.lastName', 'like', "%{$tok}%")
                    ->orWhere('student.email', 'like', "%{$tok}%")
                    ->orWhere('parent.firstName', 'like', "%{$tok}%")
                    ->orWhere('parent.lastName', 'like', "%{$tok}%")
                    ->orWhere('parent.email', 'like', "%{$tok}%")
                    ->orWhere('referenceNumber', 'like', "%{$tok}%")
                    ->orWhere('academic.previousSchool', 'like', "%{$tok}%");
                });
            }
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($currentPage > $lastPage) {
            $currentPage = $lastPage;
        }
        $offset = ($currentPage - 1) * $perPage;

        $applications = $query
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($perPage)
            ->get();

        $data = $applications->map(function ($app) {
            $docs = $app->documents ?? [];
            return [
                'id' => (string) $app->_id,
                'referenceNumber' => $app->referenceNumber,
                'student' => $app->student,
                'parent' => $app->parent,
                'academic' => $app->academic,
                // Presence-only flags for the list view — generating a signed
                // Cloudinary URL for every row on every page load would be
                // wasteful; the detail page fetches real signed URLs on demand.
                'files' => [
                    'birth_certificate' => !empty($docs['birth_certificate']),
                    'id_picture_1x1' => !empty($docs['id_picture_1x1']),
                ],
                'status' => $app->status,
                'submitted_at' => $app->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * GET /api/guest/enrollments/{id} (Sanctum-guarded)
     */
    public function show($id)
    {
        $application = EnrollmentApplication::find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $uploadService = app(\App\Services\DocumentUploadService::class);
        $documents = [];

        foreach ($application->documents ?? [] as $type => $doc) {
            if (!is_array($doc) || empty($doc['public_id'])) continue;
            $documents[$type] = [
                'url' => $uploadService->getSignedUrl($doc['public_id'], $doc['resource_type'] ?? 'image'),
                'name' => $doc['original_filename'] ?? null,
            ];
        }

        return response()->json([
            'data' => [
                'id' => (string) $application->_id,
                'referenceNumber' => $application->referenceNumber,
                'student' => $application->student,
                'parent' => $application->parent,
                'academic' => $application->academic,
                'signature' => $application->signature,
                'documents' => $documents,
                'status' => $application->status,
                'rejectionReason' => $application->rejectionReason,
                'submitted_at' => $application->created_at,
            ],
        ]);
    }

    /**
     * POST /api/guest/enrollments/{id}/reject (Sanctum-guarded)
     * Body: { reason: string }
     */
    public function reject(Request $request, $id)
    {
        $application = EnrollmentApplication::find($id);

        if (!$application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($application->status !== 'pending') {
            return response()->json([
                'message' => 'This application has already been ' . $application->status . '.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please provide a reason for rejecting this application.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $application->status = 'rejected';
        $application->rejectionReason = $request->input('reason');
        $application->save();

        return response()->json(['message' => 'Application has been rejected.']);
    }

    /**
     * GET /api/guest/enrollments/lookup?ref=RCAC-XXXXXX (PUBLIC — no auth)
     */
    public function lookup(Request $request)
    {
        $ref = trim((string) $request->query('ref', ''));

        if ($ref === '') {
            return response()->json(['message' => 'Please enter a reference number.'], 422);
        }

        $application = EnrollmentApplication::where('referenceNumber', strtoupper($ref))->first();

        if (!$application) {
            return response()->json(['message' => "We couldn't find an application with that reference number."], 404);
        }

        $response = [
            'referenceNumber' => $application->referenceNumber,
            'status' => $application->status,
            'studentFirstName' => $application->student['firstName'] ?? '',
        ];

        if ($application->status === 'rejected') {
            $response['rejectionReason'] = $application->rejectionReason;
        }

        return response()->json(['data' => $response]);
    }
}