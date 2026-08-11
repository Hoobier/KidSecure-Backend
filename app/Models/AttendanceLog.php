<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'attendance_logs';

    protected $fillable = [
        'studentId',
        'rfidTag',
        'type',
        'timestamp',
        'method',
    ];
}