<?php
// app/Http/Controllers/TeacherAttendanceController.php
namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TeacherAttendanceController extends Controller
{
    /**
     * GET /api/teacher/attendance-logs?date=YYYY-MM-DD&gradeLevel=&section=
     *
     * Roster-style view scoped to one {gradeLevel, section} at a time.
     * Defaults to the teacher's first home assignment.
     */
    public function index(Request $request)
    {
        $teacher = $request->user();

        $date = $request->filled('date')
            ? $request->query('date')
            : Carbon::now('Asia/Manila')->toDateString();

        $start = Carbon::parse($date, 'Asia/Manila')->startOfDay()->setTimezone('UTC');
        $end   = Carbon::parse($date, 'Asia/Manila')->endOfDay()->setTimezone('UTC');

        $gradeLevel = $request->query('gradeLevel');
        $section    = $request->query('section');

        if (!$gradeLevel || !$section) {
            $fallback = collect($teacher->homeAssignments ?? [])->first();
            if (!$fallback) {
                return response()->json(['message' => 'No home class assigned to this teacher.'], 403);
            }
            $gradeLevel = $gradeLevel ?? $fallback['gradeLevel'];
            $section    = $section    ?? $fallback['section'];
        }

        $students = Student::where('gradeLevel', $gradeLevel)
            ->where('section', $section)
            ->get(['studentId', 'firstName', 'lastName', 'section']);

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

            $hasTaps    = $taps->count() > 0;
            $extraTaps  = $taps->count() > 2 ? $taps->slice(1, -1)->values() : collect();

            return [
                'studentId'    => $student->studentId,
                'lastName'     => $student->lastName,
                'firstName'    => $student->firstName,
                'section'      => $student->section,
                'status'       => $hasTaps ? 'present' : 'absent',
                'timeIn'       => $hasTaps ? $taps->first() : null,
                'timeOut'      => $hasTaps ? $taps->last() : null,
                'hasExtraTaps' => $extraTaps->count() > 0,
                'extraTaps'    => $extraTaps,
            ];
        });

        $sorted = $roster->sortBy('lastName')->values();

        return response()->json([
            'date'       => $date,
            'gradeLevel' => $gradeLevel,
            'section'    => $section,
            'data'       => $sorted,
        ]);
    }
}