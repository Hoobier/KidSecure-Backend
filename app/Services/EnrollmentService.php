<?php

namespace App\Services;

use App\Models\Student;
use App\Models\ParentAccount;
use App\Services\FirebaseService;
use App\Services\FirebaseRealtimeService;
use App\Mail\ParentAccountCreated;
use Illuminate\Support\Facades\Mail;

class EnrollmentService
{
    /**
     * Resolves the parent (new/existing/auto-linked-by-email), runs the same
     * duplicate-student and mistyped-parent-email checks used by the walk-in
     * enrollment wizard, then creates the Student record.
     *
     * Returns a plain array — never an HTTP response — so callers decide
     * their own status codes. The 'outcome' key tells you what happened:
     *
     *   'duplicate_student'      → a likely-matching student already exists
     *   'duplicate_parent_email' → email matches an existing parent under a different name
     *   'parent_not_found'       → 'existing' mode given a bad/missing existingParentId
     *   'rfid_conflict'          → the given rfidTag is already assigned to someone
     *   'success'                → student + parent created
     *
     * $studentInput expects: firstName, middleName, lastName, dateOfBirth,
     *   gradeLevel, section, isTransferee, previousSchool, documents
     * $parentInput expects: mode ('new'|'existing'), existingParentId,
     *   firstName, lastName, relationship, email, phone
     * $options: rfidTag, confirmDuplicate, confirmParentMismatch
     */
    public function createStudentAndParent(array $studentInput, array $parentInput, array $options = []): array
    {
        $rfidTag = $options['rfidTag'] ?? null;
        $confirmDuplicate = $options['confirmDuplicate'] ?? false;
        $confirmParentMismatch = $options['confirmParentMismatch'] ?? false;
        $parentMode = $parentInput['mode'] ?? 'new';

        // Resolve "existing parent by email" ONCE, reused below in both the
        // student-duplicate same-parent check and the parent-mismatch check.
        $existingByEmail = null;
        if ($parentMode === 'new' && !empty($parentInput['email'])) {
            $existingByEmail = ParentAccount::where('email', $parentInput['email'])->first();
        }

        // ---- Step A0: Check for a likely-duplicate student (same name + DOB) ----
        if (!$confirmDuplicate) {
            $candidateMatch = Student::where('dateOfBirth', $studentInput['dateOfBirth'])
                ->get()
                ->first(function ($s) use ($studentInput) {
                    return strtolower(trim($s->firstName)) === strtolower(trim($studentInput['firstName']))
                        && strtolower(trim($s->lastName)) === strtolower(trim($studentInput['lastName']));
                });

            if ($candidateMatch) {
                $prospectiveParentId = null;

                if ($parentMode === 'existing') {
                    $prospectiveParentId = $parentInput['existingParentId'] ?? null;
                } elseif ($existingByEmail) {
                    $prospectiveParentId = (string) $existingByEmail->_id;
                }

                $sameParent = $prospectiveParentId && $prospectiveParentId === $candidateMatch->parentId;

                return [
                    'outcome' => 'duplicate_student',
                    'sameParent' => $sameParent,
                    'message' => $sameParent
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
                ];
            }
        }

        // ---- Step A0.5: Check for a likely-mistyped email (existing account, different name) ----
        if ($parentMode === 'new' && $existingByEmail && !$confirmParentMismatch) {
            $typedNameMatches =
                strtolower(trim($existingByEmail->firstName)) === strtolower(trim($parentInput['firstName'] ?? ''))
                && strtolower(trim($existingByEmail->lastName)) === strtolower(trim($parentInput['lastName'] ?? ''));

            if (!$typedNameMatches) {
                return [
                    'outcome' => 'duplicate_parent_email',
                    'message' => "This email is already registered under a different name ({$existingByEmail->firstName} {$existingByEmail->lastName}). Please confirm this is the same person, or check the email address for a typo.",
                    'existingParent' => [
                        'id' => (string) $existingByEmail->_id,
                        'fullName' => trim($existingByEmail->firstName . ' ' . $existingByEmail->lastName),
                        'email' => $existingByEmail->email,
                        'phone' => $existingByEmail->phone,
                    ],
                ];
            }
        }

        // ---- Step A: Resolve the parent (link existing, auto-link by email, or create new) ----
        $newParentPassword = null;
        $parentLinkedExisting = false;

        if ($parentMode === 'existing') {
            $parent = ParentAccount::find($parentInput['existingParentId'] ?? null);

            if (!$parent) {
                return [
                    'outcome' => 'parent_not_found',
                    'message' => 'The selected parent/guardian could not be found. Please search again.',
                ];
            }
        } else {
            if ($existingByEmail) {
                $parent = $existingByEmail;
                $parentLinkedExisting = true;
            } else {
                $parent = new ParentAccount();
                $parent->firstName = $parentInput['firstName'];
                $parent->lastName = $parentInput['lastName'];
                $parent->relationship = $parentInput['relationship'];
                $parent->email = $parentInput['email'];
                $parent->phone = $parentInput['phone'];
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

        if (!empty($rfidTag)) {
            $rfidConflict = Student::where('rfidTag', trim($rfidTag))->first();

            if ($rfidConflict) {
                return [
                    'outcome' => 'rfid_conflict',
                    'message' => "This RFID tag is already assigned to {$rfidConflict->firstName} {$rfidConflict->lastName} ({$rfidConflict->studentId}).",
                ];
            }
        }

        // ---- Step C: Create the student record ----
        $student = new Student();
        $student->studentId = $studentId;
        $student->firstName = $studentInput['firstName'];
        $student->middleName = $studentInput['middleName'] ?? '';
        $student->lastName = $studentInput['lastName'];
        $student->dateOfBirth = $studentInput['dateOfBirth'];
        $student->address = $studentInput['address'] ?? '';
        $student->gradeLevel = $studentInput['gradeLevel'];
        $student->startingGradeLevel = $studentInput['gradeLevel'];
        $student->section = $studentInput['section'] ?? '';
        $student->rfidTag = $rfidTag ?: null;
        $student->parentId = (string) $parent->_id;
        $student->enrollmentStatus = 'active';
        $student->dateEnrolled = now();
        $student->documents = $studentInput['documents'] ?? [];
        $student->isTransferee = $studentInput['isTransferee'] ?? false;
        $student->previousSchool = $studentInput['previousSchool'] ?? null;
        $student->save();

        // ---- Step D: Link the student to the parent's studentIds array ----
        $existingIds = $parent->studentIds ?? [];
        $existingIds[] = (string) $student->_id;
        $parent->studentIds = $existingIds;
        $parent->save();

        // ---- Mirror to Firebase RTDB (best-effort — Mongo stays source of truth) ----
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

        return [
            'outcome' => 'success',
            'studentId' => $studentId,
            'parentLinkedExisting' => $parentLinkedExisting,
            'student' => $student,
            'parent' => $parent,
        ];
    }
}