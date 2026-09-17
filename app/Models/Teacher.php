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
        'middleName',
        'lastName',
        'email',
        'password',
        'department',          // 'elementary' | 'preschool'
        'forteSubjectCode',    // nullable — null for preschool teachers
        'homeAssignments',     // array of ['gradeLevel' => 'Grade 2', 'section' => 'A']
        'visitingAssignments', // same shape; subject is always forteSubjectCode
        'status',              // 'active' | 'deleted'
    ];

    protected $hidden = ['password'];

    /**
     * Does this teacher hold the given grade+section as a home (advisory) class?
     */
    public function isHomeSection(string $gradeLevel, string $section): bool
    {
        return collect($this->homeAssignments ?? [])->contains(function ($a) use ($gradeLevel, $section) {
            return ($a['gradeLevel'] ?? null) === $gradeLevel
                && ($a['section'] ?? null) === $section;
        });
    }

    /**
     * Does this teacher hold the given grade+section as a visiting class?
     */
    public function isVisitingSection(string $gradeLevel, string $section): bool
    {
        return collect($this->visitingAssignments ?? [])->contains(function ($a) use ($gradeLevel, $section) {
            return ($a['gradeLevel'] ?? null) === $gradeLevel
                && ($a['section'] ?? null) === $section;
        });
    }

    /**
     * Distinct grade levels this teacher visits (elementary specialists only).
     */
    public function visitingGradeLevels(): array
    {
        return collect($this->visitingAssignments ?? [])
            ->pluck('gradeLevel')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}