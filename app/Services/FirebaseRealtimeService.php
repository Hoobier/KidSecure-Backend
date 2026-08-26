<?php
// app/Services/FirebaseRealtimeService.php
namespace App\Services;

use App\Models\ParentAccount;
use App\Models\Student;
use App\Models\AttendanceLog;
use Kreait\Firebase\Contract\Database as FirebaseDatabase;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Contract\Messaging as FirebaseMessaging;
use Kreait\Firebase\Messaging\AndroidConfig;

class FirebaseRealtimeService
{
    public function __construct(
        protected FirebaseDatabase $db,
        protected FirebaseMessaging $messaging,)
    {
    }

    public function clearAllData(): void
    {
        $this->db->getReference('students')->remove();
        $this->db->getReference('parents')->remove();
        $this->db->getReference('entryExitLogs')->remove();
    }


    public function sendScanNotification(AttendanceLog $log): void
    {
        $student = Student::where('studentId', $log->studentId)->first();
        if (!$student) return;

        $parent = ParentAccount::whereIn('studentIds', [(string) $student->_id])->first();
        if (!$parent || empty($parent->fcmToken) || !($parent->notificationsEnabled ?? true)) {
            return;
        }

        $studentName = trim($student->firstName . ' ' . $student->lastName);
        $action = $log->type === 'in' ? 'entered' : 'left';
        $time = $log->timestamp->format('g:i A');

        $message = CloudMessage::withTarget('token', $parent->fcmToken)
            ->withNotification([
                'title' => 'KidSecure',
                'body' => "{$studentName} {$action} school at {$time}",
            ])
            ->withData([
                'studentId' => $log->studentId,
                'type' => $log->type,
            ])
            
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'kidsecure_scan_events',
                    'sound' => 'default',
                ],
            ]));

        $this->messaging->send($message);
    }
    /**
     * Writes/updates a student's basic info into RTDB.
     * Uses the STUDENT'S FIREBASE UID or MongoDB _id as the key.
     */
    public function mirrorStudent(Student $student): void
    {
        if (empty($student->studentId)) {
            return;
        }

        // Pull this student's most recent attendance log so we mirror their
        // real current status, instead of assuming. Calls to this method
        // that have nothing to do with attendance (e.g. editing a student's
        // name) will naturally just re-mirror whatever their last real scan
        // was, which is correct — their IN/OUT status hasn't changed.
        $lastLog = \App\Models\AttendanceLog::where('studentId', $student->studentId)
            ->orderBy('timestamp', 'desc')
            ->first();

        $this->db
            ->getReference("students/{$student->studentId}") // Using human-readable ID
            ->update([
                'fullName' => trim($student->firstName . ' ' . $student->lastName),
                'gradeSection' => trim($student->gradeLevel . ' - ' . $student->section),
                'photoUrl' => $student->photoUrl ?? null,
                'status' => $lastLog->type ?? 'out',
                'lastScanTime' => $lastLog
                    ? $lastLog->timestamp->timestamp * 1000
                    : now()->timestamp * 1000,
                'enrollmentStatus' => $student->enrollmentStatus ?? 'active',
            ]);
    }

    /**
     * Writes/updates a parent's profile into RTDB, keyed by their
     * Firebase Auth UID.
     */
    public function mirrorParent(ParentAccount $parent): void
    {
        if (empty($parent->firebaseUid)) {
            return;
        }

        // Get human-readable student IDs
        $studentIds = $this->resolveHumanReadableStudentIds($parent->studentIds ?? []);

        $this->db
            ->getReference("parents/{$parent->firebaseUid}")
            ->update([
                'fullName' => trim($parent->firstName . ' ' . $parent->lastName),
                'email' => $parent->email,
                'phone' => $parent->phone,
                'studentIds' => $studentIds, // Store as simple array, not key-value
                'notificationsEnabled' => $parent->notificationsEnabled ?? true,
            ]);
    }

    /**
     * Converts an array of Mongo Student _ids into human-readable
     * studentId strings (e.g. "2026-0001").
     */
    private function resolveHumanReadableStudentIds(array $mongoIds): array
    {
        if (empty($mongoIds)) {
            return [];
        }

        return Student::whereIn('_id', $mongoIds)
            ->pluck('studentId')
            ->filter()
            ->values()
            ->all();
    }

     public function mirrorAttendanceLog(AttendanceLog $log): void
    {
        // Get student info for the log
        $student = Student::where('studentId', $log->studentId)->first();
        
        $studentName = $student 
            ? trim($student->firstName . ' ' . $student->lastName) 
            : $log->studentId;

        $this->db
            ->getReference("entryExitLogs/{$log->studentId}/{$log->_id}")
            ->update([
                'status' => $log->type, // 'in' or 'out'
                'timestamp' => $log->timestamp->timestamp * 1000, // milliseconds
                'studentName' => $studentName,
                'rfidTag' => $log->rfidTag,
                'method' => $log->method ?? 'rfid',
            ]);
    }

    /**
     * Sync all existing logs for a student to RTDB
     */
    public function syncStudentLogs(Student $student): void
    {
        $logs = AttendanceLog::where('studentId', $student->studentId)->get();
        
        foreach ($logs as $log) {
            $this->mirrorAttendanceLog($log);
        }
    }
}