<?php
// app/Models/Teacher.php
namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Teacher extends Model
{
    use Notifiable, HasApiTokens;

    protected $connection = 'mongodb';
    protected $collection = 'teachers';

    protected $fillable = [
        'firstName',
        'lastName',
        'email',
        'password',
        'department',        // 'elementary' | 'preschool'
        'homeGradeLevel',    // e.g. 'Grade 2', 'Nursery'
        'forteSubjectCode',  // nullable — null for preschool teachers
    ];

    protected $hidden = ['password'];
}