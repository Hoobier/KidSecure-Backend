<?php
// app/Models/ParentAccount.php
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use App\Models\Student;

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
        'isDeleted',
        'deletedAt',
        'archivedAt',
    ];

    protected $casts = [
        'archivedAt' => 'datetime',
    ];

    public function allLinkedStudentsHaveLeft(): bool
    {
        $studentIds = $this->studentIds ?? [];
    
        if (empty($studentIds)) {
            return false;
        }
    
        $stillHereCount = Student::whereIn('_id', $studentIds)
            ->whereNotIn('enrollmentStatus', ['graduated', 'transferred_out', 'deleted'])
            ->count();
    
        return $stillHereCount === 0;
    }
}