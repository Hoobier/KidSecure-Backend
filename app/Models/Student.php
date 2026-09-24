<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Student extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'students';

    protected $fillable = [
        'studentId',
        'firstName',
        'middleName',
        'lastName',
        'dateOfBirth',
        'address',
        'gradeLevel',
        'startingGradeLevel',
        'section',
        'enrollmentStatus',
        'photoUrl',
        'rfidTag',
        'parentId',
        'dateEnrolled',
        'documents',
        'isTransferee',
        'previousSchool',
        'reportCard',
        'observedValues',
        'attendanceByMonth',
        'reportCardReleasedAt',
        'reportCardSubmittedAt',
        'reportCardSubmittedTerm',
        'reportCardReleasedTerm',
        'reportCardLockedTerms',
        'archivedAt',
    ];


    protected $casts = [
        'reportCardSubmittedAt'      => 'datetime',
        'reportCardReleasedAt'       => 'datetime',
        'archivedAt' => 'datetime',
    ];

    public function upsertDocument(array $newDocument): void
    {
        $documents = $this->documents ?? [];

        $found = false;
        foreach ($documents as $index => $doc) {
            if ($doc['type'] === $newDocument['type']) {
                $documents[$index] = $newDocument;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $documents[] = $newDocument;
        }

        $this->documents = $documents;
        $this->save();
    }

    /**
     * True when all three terms have been released for this student.
     * Attendance locks at that point — advisory edits blocked.
     */
    public function attendanceLocked(): bool
    {
        $locked = $this->reportCardLockedTerms ?? [];
        if (!is_array($locked)) return false;

        return in_array('T1', $locked, true)
            && in_array('T2', $locked, true)
            && in_array('T3', $locked, true);
    }
}