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
}