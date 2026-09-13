<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TeacherAttendanceController extends Controller
{
    /**
     * GET /api/teacher/attendance-logs
     *
     * Roster-style view scoped to the teacher's home grade (all sections).
     * Every student in the grade appears as a row, present or absent.
     * Optional: ?date=YYYY-MM-DD (defaults to today, Asia/Manila).
     */
    public function index(Request $request)
    {
        $teacher = $request->user();

        $date = $request->filled('date')
            ? $request->query('date')
            : Carbon::now('Asia/Manila')->toDateString();

        $start = Carbon::parse($date, 'Asia/Manila')->startOfDay()->setTimezone('UTC');
        $end = Carbon::parse($date, 'Asia/Manila')->endOfDay()->setTimezone('UTC');

        // Base roster: every student in the teacher's home grade, all sections.
        $students = Student::where('gradeLevel', $teacher->homeGradeLevel)
            ->get(['studentId', 'firstName', 'lastName', 'section']);

        // All taps for this grade's students, for the given day.
        $studentIds = $students->pluck('studentId')->all();
        $logs = AttendanceLog::whereIn('studentId', $studentIds)
            ->whereBetween('timestamp', [$start, $end])
            ->orderBy('timestamp', 'asc')
            ->get()
            ->groupBy('studentId');

        $roster = $students->map(function ($student) use ($logs) {
            $studentLogs = $logs->get($student->studentId, collect());

            $taps = $studentLogs->map(function ($log) {
                return Carbon::parse($log->timestamp)->toIso8601String();
            })->values();

            $hasTaps = $taps->count() > 0;
            $extraTaps = $taps->count() > 2
                ? $taps->slice(1, -1)->values()
                : collect();

            return [
                'studentId' => $student->studentId,
                'lastName' => $student->lastName,
                'firstName' => $student->firstName,
                'section' => $student->section,
                'status' => $hasTaps ? 'present' : 'absent',
                'timeIn' => $hasTaps ? $taps->first() : null,
                'timeOut' => $hasTaps ? $taps->last() : null,
                'hasExtraTaps' => $extraTaps->count() > 0,
                'extraTaps' => $extraTaps,
            ];
        });

        $sorted = $roster->sortBy('lastName')->values();

        return response()->json([
            'date' => $date,
            'gradeLevel' => $teacher->homeGradeLevel,
            'data' => $sorted,
        ]);
    }
}