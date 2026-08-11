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
        'gradeLevel',
        'section',
        'enrollmentStatus',
        'photoUrl',
        'rfidTag',
        'parentId',
        'dateEnrolled',
    ];
}