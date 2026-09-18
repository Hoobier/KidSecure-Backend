<?php
// app/Http/Controllers/SubjectController.php
namespace App\Http\Controllers;

class SubjectController extends Controller
{
    /**
     * GET /api/subjects-by-grade
     *
     * Returns the full subject configuration from config/school.php so
     * every frontend can share one source of truth instead of hardcoding
     * per-grade subject lists.
     */
    public function index()
    {
        return response()->json([
            'data' => [
                'subjects'         => config('school.subjects', []),
                'subjects_by_grade' => config('school.subjects_by_grade', []),
                'subject_groups'   => config('school.subject_groups', []),
            ],
        ]);
    }
}