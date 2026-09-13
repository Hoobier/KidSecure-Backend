<?php
// app/Http/Controllers/DeviceScanController.php
namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\FirebaseRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DeviceScanController extends Controller
{
    // Shared with EnrollmentRfidController below.
    const LISTEN_KEY = 'rfid_enrollment_listen';

    /**
     * Turnstile reader only. Pure attendance in/out logic — this method
     * has no awareness of the enrollment "listening for a new tag" flow
     * at all, on purpose. A scan here always means a student is entering
     * or leaving, full stop, regardless of what's happening elsewhere
     * in the admin portal.
     */
    public function turnstileScan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rfidTag' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'result' => 'denied',
                'reason' => 'Missing or invalid RFID tag.',
            ], 422);
        }

        $rfidTag = trim($request->input('rfidTag'));

        $student = Student::where('rfidTag', $rfidTag)
            ->whereIn('enrollmentStatus', ['active'])
            ->first();

        if (!$student) {
            return response()->json([
                'result' => 'denied',
                'reason' => 'Unrecognized tag.',
            ]);
        }

        $lastLog = AttendanceLog::where('studentId', $student->studentId)
            ->orderBy('timestamp', 'desc')
            ->first();

        $newType = ($lastLog && $lastLog->type === 'in') ? 'out' : 'in';

        $log = new AttendanceLog();
        $log->studentId = $student->studentId;
        $log->rfidTag = $rfidTag;
        $log->type = $newType;
        $log->timestamp = now();
        $log->method = 'rfid';
        $log->save();

        try {
            app(FirebaseRealtimeService::class)->mirrorAttendanceLog($log);
            app(FirebaseRealtimeService::class)->mirrorStudent($student);
            app(FirebaseRealtimeService::class)->sendScanNotification($log);
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after scan for student {$student->studentId}: " . $e->getMessage());
        }

        return response()->json([
            'result' => 'granted',
            'type' => $newType,
            'studentName' => trim($student->firstName . ' ' . $student->lastName),
        ]);
    }

    /**
     * Registration reader only. This method has no awareness of student
     * attendance or the turnstile at all — it only ever checks the
     * enrollment "listening for a new tag" cache slot and reports back
     * whether a tag was captured for the admin portal to pick up.
     */
    public function registrationScan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rfidTag' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'result' => 'denied',
                'reason' => 'Missing or invalid RFID tag.',
            ], 422);
        }

        $rfidTag = trim($request->input('rfidTag'));

        $listen = Cache::get(self::LISTEN_KEY);

        if (!$listen || !($listen['active'] ?? false)) {
            return response()->json([
                'result' => 'denied',
                'reason' => 'No registration session is currently open. Click "Scan New Card" in the admin portal first.',
            ]);
        }

        $existing = Student::where('rfidTag', $rfidTag)
            ->when($listen['excludeStudentId'] ?? null, function ($q, $excludeId) {
                $q->where('_id', '!=', $excludeId);
            })
            ->first();

        if ($existing) {
            Cache::put(self::LISTEN_KEY, array_merge($listen, [
                'active' => false,
                'result' => [
                    'status' => 'duplicate',
                    'studentName' => trim($existing->firstName . ' ' . $existing->lastName),
                ],
            ]), now()->addSeconds(20));
        } else {
            Cache::put(self::LISTEN_KEY, array_merge($listen, [
                'active' => false,
                'result' => [
                    'status' => 'new',
                    'rfidTag' => $rfidTag,
                ],
            ]), now()->addSeconds(20));
        }

        // Neutral response — the admin portal is what actually tells the
        // admin "new" vs "duplicate"; the reader just needs to know the
        // tag was captured.
        return response()->json([
            'result' => 'noted',
            'reason' => 'Tag received for registration.',
        ]);
    }
}