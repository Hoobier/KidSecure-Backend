<?php
// app/Services/GradeSubjectService.php
namespace App\Services;

class GradeSubjectService
{
    /**
     * Entry codes — what teachers write grades against, stored in
     * Student.reportCard.
     */
    public static function allEntryCodes(): array
    {
        $codes = [];
        foreach (config('school.entry_subjects_by_grade', []) as $list) {
            foreach ($list as $code) {
                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }
        return $codes;
    }

    public static function entryCodesForGrade(?string $gradeLevel): array
    {
        if (!$gradeLevel) return [];
        return config("school.entry_subjects_by_grade.{$gradeLevel}", []);
    }

    /**
     * Display codes — what shows on the report card. Same as entry codes
     * except computed subjects (like MAPEH) replace their components.
     */
    public static function displayCodesForGrade(?string $gradeLevel): array
    {
        if (!$gradeLevel) return [];
        return config("school.display_subjects_by_grade.{$gradeLevel}", []);
    }

    /**
     * For a computed display code, which component codes feed it.
     */
    public static function componentsFor(?string $displayCode): array
    {
        if (!$displayCode) return [];
        return config("school.computed_subjects.{$displayCode}.components", []);
    }

    public static function isComputed(?string $code): bool
    {
        if (!$code) return false;
        return array_key_exists($code, config('school.computed_subjects', []));
    }

    public static function isSubjectOfferedAtGrade(?string $subjectCode, ?string $gradeLevel): bool
    {
        if (!$subjectCode || !$gradeLevel) return false;
        return in_array($subjectCode, self::entryCodesForGrade($gradeLevel), true);
    }

    /**
     * Compute the value of a display-only subject for one term, from the
     * student's report card. Returns null when any component is missing.
     * Uses ceiling rounding.
     */
    public static function computedGradeForTerm(array $reportCard, string $displayCode, string $term): ?int
    {
        $components = self::componentsFor($displayCode);
        if (empty($components)) return null;

        $grades = [];
        foreach ($components as $code) {
            $g = $reportCard[$code][$term]['grade'] ?? null;
            if ($g === null || $g === '' || !is_numeric($g)) {
                return null;
            }
            $grades[] = (float) $g;
        }

        return (int) ceil(array_sum($grades) / count($grades));
    }

    /**
     * Final grade for a computed subject: ceiling average of its three
     * term values. Returns null when any term is incomplete.
     */
    public static function computedFinalGrade(array $reportCard, string $displayCode): ?int
    {
        $termValues = [];
        foreach (['T1', 'T2', 'T3'] as $term) {
            $v = self::computedGradeForTerm($reportCard, $displayCode, $term);
            if ($v === null) return null;
            $termValues[] = $v;
        }

        return (int) ceil(array_sum($termValues) / count($termValues));
    }

    /**
     * Average of a regular subject across its three terms. Rounded to
     * two decimals. Returns null when no term has a grade.
     */
    public static function finalGradeForSubject(array $reportCard, string $subjectCode): ?float
    {
        $grades = [];
        foreach (['T1', 'T2', 'T3'] as $term) {
            $g = $reportCard[$subjectCode][$term]['grade'] ?? null;
            if ($g !== null && $g !== '' && is_numeric($g)) {
                $grades[] = (float) $g;
            }
        }
        if (empty($grades)) return null;
        return round(array_sum($grades) / count($grades), 2);
    }

    /**
     * Per-term average across all *display* subjects for a grade, using
     * the computed value for computed subjects. Used for "Average per Term".
     */
    public static function termAverage(array $reportCard, string $gradeLevel, string $term): ?float
    {
        $codes = self::displayCodesForGrade($gradeLevel);
        $values = [];
        foreach ($codes as $code) {
            if (self::isComputed($code)) {
                $v = self::computedGradeForTerm($reportCard, $code, $term);
            } else {
                $g = $reportCard[$code][$term]['grade'] ?? null;
                $v = ($g !== null && $g !== '' && is_numeric($g)) ? (float) $g : null;
            }
            if ($v === null) return null;
            $values[] = $v;
        }
        if (empty($values)) return null;
        return round(array_sum($values) / count($values), 2);
    }

    /**
     * General average: average of the three term averages.
     */
    public static function generalAverage(array $reportCard, string $gradeLevel): ?float
    {
        $terms = [];
        foreach (['T1', 'T2', 'T3'] as $term) {
            $v = self::termAverage($reportCard, $gradeLevel, $term);
            if ($v === null) return null;
            $terms[] = $v;
        }
        if (empty($terms)) return null;
        return round(array_sum($terms) / count($terms), 2);
    }

    /**
     * Descriptor + remark for a given final grade.
     */
    public static function descriptorFor(?float $grade): ?array
    {
        if ($grade === null) return null;
        $g = (int) floor($grade);
        foreach (config('school.descriptors', []) as $d) {
            if ($g >= $d['min'] && $g <= $d['max']) return $d;
        }
        return null;
    }
}