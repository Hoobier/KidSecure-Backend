<?php
// app/Services/GradeLevelService.php
namespace App\Services;

class GradeLevelService
{
    public static function order(): array
    {
        return config('school.grade_levels');
    }

    public static function isValidGrade(string $gradeLevel): bool
    {
        return in_array($gradeLevel, self::order(), true);
    }

    public static function isRegularEnrollmentGrade(string $gradeLevel): bool
    {
        return in_array($gradeLevel, ['Nursery', 'Grade 1'], true);
    }

    public static function isEnrollmentTypeAllowed(string $gradeLevel, bool $isTransferee): bool
    {
        return $isTransferee !== self::isRegularEnrollmentGrade($gradeLevel);
    }

    /**
     * True when $gradeLevel is the highest grade the school offers —
     * a student here graduates instead of promoting, unless retained.
     */
    public static function isGraduatingGrade(string $gradeLevel): bool
    {
        $order = self::order();

        return end($order) === $gradeLevel;
    }

    /**
     * The grade a student moves into if promoted. Returns null for the
     * graduating grade — there is no "next" grade, so callers should
     * check isGraduatingGrade() first rather than treating null as
     * an error condition.
     *
     * @throws \InvalidArgumentException if $gradeLevel doesn't match
     *         any entry in config('school.grade_levels'). This is
     *         intentional rather than a silent skip: a mismatch here
     *         almost always means a typo or stale value on the
     *         student's record, and rollover shouldn't guess what to
     *         do with a student it doesn't recognize.
     */
    public static function nextGrade(string $gradeLevel): ?string
    {
        $order = self::order();
        $index = array_search($gradeLevel, $order, true);

        if ($index === false) {
            throw new \InvalidArgumentException("Unrecognized grade level: \"{$gradeLevel}\".");
        }

        return $order[$index + 1] ?? null;
    }
}