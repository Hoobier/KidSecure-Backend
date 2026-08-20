<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\FirebaseRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Receives scan events from the ESP32 turnstile. Authenticated via
 * VerifyDeviceSecret middleware — see routes/api.php.
 */
class DeviceScanController extends Controller
{
    /**
     * POST /api/device/scan
     * Body: { "rfidTag": "A3F2910C" }
     */
    public function scan(Request $request)
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

        // Auto-toggle: look at this student's most recent log entry to
        // decide whether this new scan is an IN or an OUT.
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
        } catch (\Throwable $e) {
            \Log::error("RTDB mirror failed after scan for student {$student->studentId}: " . $e->getMessage());
        }

        return response()->json([
            'result' => 'granted',
            'type' => $newType,
            'studentName' => trim($student->firstName . ' ' . $student->lastName),
        ]);
    }
}