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
    ];

    public function allLinkedStudentsHaveLeft(): bool
    {
        $studentIds = $this->studentIds ?? [];

        if (empty($studentIds)) {
            return false;
        }

        $stillActiveCount = Student::whereIn('_id', $studentIds)
            ->where('enrollmentStatus', 'active')
            ->count();

        return $stillActiveCount === 0;
    }
}