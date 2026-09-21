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
            ],
        ]);
    }
}