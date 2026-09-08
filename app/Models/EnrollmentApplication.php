<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class EnrollmentApplication extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'enrollment_applications';

    protected $fillable = [
        'referenceNumber',
        'student',
        'parent',
        'academic',
        'isTransferee',
        'documentsFollowUp',
        'signature',
        'documents',
        'status',
        'rejectionReason',
        'convertedStudentId',
    ];

    // No casts needed — MongoDB stores nested arrays/objects natively.
    // Adding an "array" cast here would force Laravel to JSON-encode
    // them into plain strings instead, which breaks the ability to
    // query nested fields like student.lastName directly in Mongo.

    protected $attributes = [
        'status' => 'pending',
        'documents' => [],
    ];

    protected const REFERENCE_CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    protected const REFERENCE_LENGTH = 6;

    protected static function boot()
    {
        parent::boot();

        static::creating(function (EnrollmentApplication $application) {
            if (empty($application->referenceNumber)) {
                $application->referenceNumber = self::generateUniqueReferenceNumber();
            }
        });
    }

    public static function generateUniqueReferenceNumber(): string
    {
        do {
            $code = substr(str_shuffle(str_repeat(self::REFERENCE_CHARSET, 4)), 0, self::REFERENCE_LENGTH);
            $candidate = 'RCAC-' . $code;
        } while (self::where('referenceNumber', $candidate)->exists());

        return $candidate;
    }
}