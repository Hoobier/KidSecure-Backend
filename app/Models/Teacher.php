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
        'homeAssignments',     // [{gradeLevel, section, subjects: [...]}]
        'visitingAssignments', // [{gradeLevel, section, subjects: [...]}]
        'status',              // 'active' | 'deleted'
    ];

    protected $hidden = ['password'];

    public function isHomeSection(string $gradeLevel, string $section): bool
    {
        return collect($this->homeAssignments ?? [])->contains(function ($a) use ($gradeLevel, $section) {
            return ($a['gradeLevel'] ?? null) === $gradeLevel
                && ($a['section'] ?? null) === $section;
        });
    }

    public function isVisitingSection(string $gradeLevel, string $section): bool
    {
        return collect($this->visitingAssignments ?? [])->contains(function ($a) use ($gradeLevel, $section) {
            return ($a['gradeLevel'] ?? null) === $gradeLevel
                && ($a['section'] ?? null) === $section;
        });
    }

    /**
     * Return the subjects this teacher teaches in the given class.
     * If they hold the class as both home AND visiting, returns the union.
     * Returns [] when they have no assignment here.
     */
    public function subjectsFor(string $gradeLevel, string $section): array
    {
        $subjects = [];
        foreach (['homeAssignments', 'visitingAssignments'] as $field) {
            foreach ($this->{$field} ?? [] as $a) {
                if (($a['gradeLevel'] ?? null) !== $gradeLevel) continue;
                if (($a['section'] ?? null) !== $section) continue;
                foreach ($a['subjects'] ?? [] as $code) {
                    if (!in_array($code, $subjects, true)) {
                        $subjects[] = $code;
                    }
                }
            }
        }
        return $subjects;
    }

    /**
     * All distinct subject codes this teacher is assigned to anywhere.
     */
    public function allAssignedSubjects(): array
    {
        $codes = [];
        foreach (['homeAssignments', 'visitingAssignments'] as $field) {
            foreach ($this->{$field} ?? [] as $a) {
                foreach ($a['subjects'] ?? [] as $code) {
                    if (!in_array($code, $codes, true)) {
                        $codes[] = $code;
                    }
                }
            }
        }
        return $codes;
    }

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
