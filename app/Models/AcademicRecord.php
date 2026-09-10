<?php
// app/Models/AcademicRecord.php
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AcademicRecord extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'academic_records';

    protected $fillable = [
        'studentId',
        'schoolYearLabel',
        'gradeLevel',
        'section',
        'finalStatus',
        'loyaltyAwardEligible',
        'transferNote',
        'reportCard',
    ];

    protected $casts = [
        'loyaltyAwardEligible' => 'boolean',
    ];
}