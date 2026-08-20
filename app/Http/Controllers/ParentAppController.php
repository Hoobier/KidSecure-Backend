<?php
// app/Http/Controllers/ParentAppController.php
namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\FirebaseRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoints for the Flutter parent app, authenticated via Firebase ID
 * tokens (see VerifyFirebaseToken middleware) — completely separate from
 * the admin portal's Sanctum cookie auth.
 */
class ParentAppController extends Controller
{
    /**
     * GET /api/app/me
     * Returns the logged-in parent's profile + their linked children.
     */
    public function me(Request $request)
    {
        $parent = $request->attributes->get('parentAccount');

        $students = Student::whereIn('enrollmentStatus', ['active', 'inactive'])
            ->where('parentId', (string) $parent->_id)
            ->get(['studentId', 'firstName', 'lastName', 'gradeLevel', 'section', 'enrollmentStatus']);

        $children = $students->map(function ($s) {
            return [
                'id'           => (string) $s->_id,
                'studentId'    => $s->studentId,
                'fullName'     => trim($s->firstName . ' ' . $s->lastName),
                'gradeSection' => trim($s->gradeLevel . ' - ' . $s->section),
                'status'       => $s->enrollmentStatus ?? 'active',
            ];
        })->values();

        return response()->json([
            'data' => [
                'id'                    => (string) $parent->_id,
                'firstName'             => $parent->firstName,
                'lastName'              => $parent->lastName,
                'fullName'              => trim($parent->firstName . ' ' . $parent->lastName),
                'email'                 => $parent->email,
                'phone'                 => $parent->phone,
                'notificationsEnabled'  => $parent->notificationsEnabled ?? true,
                'children'              => $children,
            ],
        ]);
    }

    /**
     * PATCH /api/app/me
     * Lets a parent update their own name/phone. Email is intentionally
     * NOT editable here — it's tied to their Firebase Auth login identity,
     * and changing it in Mongo without also changing it in Firebase Auth
     * would desync login email from displayed email. If you want email
     * changes later, that needs to call Firebase Admin SDK's updateUser()
     * too, same as FirebaseService::resetParentPassword() does for passwords.
     */
    public function update(Request $request)
    {
        $parent = $request->attributes->get('parentAccount');

        $validator = Validator::make($request->all(), [
            'firstName' => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'lastName'  => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z\s\-\'.]{2,50}$/'],
            'phone'     => ['sometimes', 'required', 'string', 'regex:/^09\d{9}$/'],
        ], [
            'firstName.regex' => 'First name may only contain letters, spaces, hyphens, apostrophes, and periods.',
            'lastName.regex'  => 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods.',
            'phone.regex'     => 'Please enter a valid 11-digit Philippine mobile number.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please check your details and try again.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (isset($data['firstName'])) $parent->firstName = $data['firstName'];
        if (isset($data['lastName']))  $parent->lastName  = $data['lastName'];
        if (isset($data['phone']))     $parent->phone     = $data['phone'];
        $parent->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorParent($parent);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after parent-app self update for parent {$parent->_id}: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Your information has been updated.',
            'data' => [
                'id'        => (string) $parent->_id,
                'firstName' => $parent->firstName,
                'lastName'  => $parent->lastName,
                'phone'     => $parent->phone,
            ],
        ]);
    }

    /**
     * PATCH /api/app/notifications
     * Body: { "enabled": true|false }
     * Persists the preference server-side (survives reinstall/new device),
     * replacing the SharedPreferences-only toggle currently in
     * settings_screen.dart.
     */
    public function updateNotifications(Request $request)
    {
        $parent = $request->attributes->get('parentAccount');

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.'], 422);
        }

        $parent->notificationsEnabled = $request->boolean('enabled');
        $parent->save();

        return response()->json([
            'message' => 'Notification preference updated.',
            'notificationsEnabled' => $parent->notificationsEnabled,
        ]);
    }
}