<?php
// app/Services/GradeSubjectService.php
namespace App\Services;

class GradeSubjectService
{
    /**
     * Subject codes offered at a given grade level.
     * Returns [] if the grade isn't in the config.
     */
    public static function subjectsForGrade(?string $gradeLevel): array
    {
        if (!$gradeLevel) {
            return [];
        }
        return config("school.subjects_by_grade.{$gradeLevel}", []);
    }

    /**
     * Is a subject code offered at a given grade level?
     */
    public static function isSubjectOfferedAtGrade(?string $subjectCode, ?string $gradeLevel): bool
    {
        if (!$subjectCode || !$gradeLevel) {
            return false;
        }
        return in_array($subjectCode, self::subjectsForGrade($gradeLevel), true);
    }
}