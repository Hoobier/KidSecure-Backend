<?php
 
namespace App\Services;
 
use App\Models\ParentAccount;
use App\Models\Student;
use Kreait\Firebase\Contract\Database as FirebaseDatabase;
 
/**
 * Mirrors student and parent data from MongoDB (source of truth) into
 * Firebase Realtime Database, which the Flutter parent app reads from.
 *
 * Laravel remains the ONLY writer to RTDB — the Flutter app only reads.
 * This keeps Mongo and RTDB from ever disagreeing about who's authoritative.
 *
 * IMPORTANT: students are mirrored using the human-readable `studentId`
 * (e.g. "2026-0001") as the RTDB key, NOT the Mongo `_id`. This is
 * deliberate: ParentAccount.studentIds stores Mongo _ids, but
 * AttendanceLog.studentId uses the human-readable format. Mirroring by
 * studentId keeps every RTDB path consistent with how attendance logs
 * are already keyed, so the Flutter app never has to reconcile two
 * different ID formats.
 */
class FirebaseRealtimeService
{
    public function __construct(protected FirebaseDatabase $db)
    {
    }
 
    /**
     * Writes/updates a student's basic info into RTDB.
     *
     * Uses update() rather than set() on purpose: this method only ever
     * touches enrollment-related fields (name, grade, section, status).
     * currentStatus/lastScanTime are written separately by the future
     * /api/rfid/scan endpoint, and must NOT be wiped out just because an
     * admin edited a student's grade level.
     */
    public function mirrorStudent(Student $student): void
    {
        if (empty($student->studentId)) {
            // Safety guard: a student without a human-readable ID yet
            // (shouldn't happen post-enrollment, but avoids writing a
            // garbage RTDB node if it ever does).
            return;
        }
 
        $this->db
            ->getReference("students/{$student->studentId}")
            ->update([
                'firstName' => $student->firstName,
                'lastName' => $student->lastName,
                'gradeLevel' => $student->gradeLevel,
                'section' => $student->section,
                'enrollmentStatus' => $student->enrollmentStatus ?? 'active',
            ]);
    }
 
    /**
     * Writes/updates a parent's profile into RTDB, keyed by their
     * Firebase Auth UID (this IS the correct key for parents — Flutter
     * looks up parents/{currentUser.uid} directly after login).
     *
     * Translates ParentAccount.studentIds (Mongo _ids) into the
     * human-readable studentId format used everywhere else in RTDB.
     */
    public function mirrorParent(ParentAccount $parent): void
    {
        if (empty($parent->firebaseUid)) {
            // No Firebase account yet (e.g. account creation failed and
            // was logged, but never retried) — nothing to mirror to.
            return;
        }
 
        $studentIds = $this->resolveHumanReadableStudentIds($parent->studentIds ?? []);
 
        $this->db
            ->getReference("parents/{$parent->firebaseUid}")
            ->update([
                'firstName' => $parent->firstName,
                'lastName' => $parent->lastName,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'studentIds' => empty($studentIds)
                    ? null
                    : array_fill_keys($studentIds, true),
            ]);
    }
 
    /**
     * Converts an array of Mongo Student _ids into human-readable
     * studentId strings (e.g. "2026-0001"), skipping any that can't
     * be resolved (e.g. a stale/removed student reference).
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
}
 