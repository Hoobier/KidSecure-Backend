<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ReportCardRevision extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'report_card_revisions';

    protected $fillable = [
        'studentId',
        'subjectCode',
        'term',
        'note',
        'changedAt',
    ];
}