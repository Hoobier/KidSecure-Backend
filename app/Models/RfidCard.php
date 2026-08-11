<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class RfidCard extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'rfid_cards';

    protected $fillable = [
        'tagId',
        'studentId',
        'status',
        'issuedDate',
        'deactivatedDate',
    ];
}