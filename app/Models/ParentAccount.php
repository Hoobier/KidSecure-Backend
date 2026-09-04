<?php
// app/Models/ParentAccount.php
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ParentAccount extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'parents';

    protected $fillable = [
        'firstName',
        'lastName',
        'email',
        'phone',
        'relationship',
        'firebaseUid',
        'studentIds',
        'accountCreatedAt',
        'notificationsEnabled',
        'fcmToken',
    ];
}