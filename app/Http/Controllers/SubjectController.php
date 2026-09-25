<?php
// app/Http/Controllers/SubjectController.php
namespace App\Http\Controllers;

class SubjectController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => [
                'subjects'                 => config('school.subjects', []),
                'entry_subjects_by_grade'  => config('school.entry_subjects_by_grade', []),
                'display_subjects_by_grade'=> config('school.display_subjects_by_grade', []),
                'computed_subjects'        => config('school.computed_subjects', []),
                'descriptors'              => config('school.descriptors', []),
                'observed_values'         => config('school.observed_values', []),
                'observed_value_ratings'  => config('school.observed_value_ratings', []),
                'monthly_school_days' => \App\Models\TermSetting::current()->monthlySchoolDays ?? new \stdClass(),
                'tardy_cutoff' => \App\Models\TermSetting::current()->tardyCutoff ?? '08:00',
                'school_months' => config('school.school_months', []),
            ],
        ]);
    }
}