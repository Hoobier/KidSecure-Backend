<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * GET /api/attendance-logs
     *
     * Flat, one-row-per-tap log. Each row: studentId, name, timestamp, type ('in'|'out').
     * Optional filters: date, name (search), gradeLevel, section.
     *
     * Grade/section/name filters apply to Student first (Mongo has no native join),
     * then we narrow AttendanceLog by the resulting studentId list.
     */
    public function index(Request $request)
    {
        $hasStudentFilter = $request->filled('name')
            || $request->filled('gradeLevel')
            || $request->filled('section');

        // Lookup map for every student (not just filtered ones), so names
        // still render correctly on rows even when no student filter is active.
        $allStudents = Student::all(['studentId', 'firstName', 'lastName']);
        $namesById = $allStudents->mapWithKeys(function ($s) {
            return [$s->studentId => trim($s->firstName . ' ' . $s->lastName)];
        });

        $query = AttendanceLog::query();

        if ($request->filled('date')) {
            $start = Carbon::parse($request->query('date'), 'Asia/Manila')
                ->startOfDay()
                ->setTimezone('UTC');
            $end = Carbon::parse($request->query('date'), 'Asia/Manila')
                ->endOfDay()
                ->setTimezone('UTC');
            $query->whereBetween('timestamp', [$start, $end]);
        }

        if ($hasStudentFilter) {
            $studentQuery = Student::query();

            if ($request->filled('name')) {
                $needle = $request->query('name');
                $studentQuery->where(function ($q) use ($needle) {
                    $q->where('firstName', 'like', "%{$needle}%")
                      ->orWhere('lastName', 'like', "%{$needle}%");
                });
            }

            if ($request->filled('gradeLevel')) {
                $studentQuery->where('gradeLevel', $request->query('gradeLevel'));
            }

            if ($request->filled('section')) {
                $studentQuery->where('section', $request->query('section'));
            }

            $matchingIds = $studentQuery->pluck('studentId')->all();
            $query->whereIn('studentId', $matchingIds);
        }

        $logs = $query->orderBy('timestamp', 'desc')->get();

        $result = $logs->map(function ($log) use ($namesById) {
            return [
                'studentId' => $log->studentId,
                'name' => $namesById->get($log->studentId, $log->studentId),
                'timestamp' => Carbon::parse($log->timestamp)->toIso8601String(),
                'type' => $log->type,
            ];
        });

        return response()->json(['data' => $result->values()]);
    }

    /**
     * GET /api/attendance-logs/filter-options
     *
     * Distinct grade levels/sections currently in use by active students,
     * so the frontend dropdowns stay in sync with real data.
     */
    public function filterOptions()
    {
        $students = Student::where('enrollmentStatus', 'active')
            ->get(['gradeLevel', 'section']);

        $gradeLevels = $students->pluck('gradeLevel')->filter()->unique()->values();
        $sections = $students->pluck('section')->filter()->unique()->sort()->values();

        return response()->json([
            'gradeLevels' => $this->sortGradeLevels($gradeLevels),
            'sections' => $sections,
        ]);
    }

    /**
     * Sorts grade levels in natural school order instead of alphabetically.
     * Adjust this list if your actual gradeLevel values differ.
     */
    private function sortGradeLevels($gradeLevel)
    {
        $order = ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];

        return $gradeLevel->sortBy(function ($lvl) use ($order) {
            $idx = array_search($lvl, $order);
            return $idx === false ? 999 : $idx;
        })->values();
    }

    /*
    |--------------------------------------------------------------------
    | FUTURE: real hardware integration
    |--------------------------------------------------------------------
    | ESP32 + MFRC522 will call this API directly over WiFi — it does NOT
    | go through the Next.js frontend or a browser.
    |
    | Flow: card tap -> ESP32 reads UID -> POST /api/rfid/scan { rfidTag }
    | (authenticated with a fixed device API key, not a user session) ->
    | Laravel finds the RfidCard by rfidTag -> resolves the linked Student
    | -> checks the student's most recent AttendanceLog row for today:
    |   - no "in" yet today  -> create AttendanceLog(type: "in")
    |   - "in" exists, no "out" yet -> create AttendanceLog(type: "out")
    |   - rfidTag not found / card inactive -> respond "denied"
    | Laravel responds { status: "in" | "out" | "denied" }; the ESP32 uses
    | that to drive the green/red LED, buzzer, and servo lock locally.
    | This page only reads whatever ends up in MongoDB.
    |
    | public function scanEvent(Request $request)
    | {
    |     $request->validate(['rfidTag' => 'required|string']);
    |     // ... lookup RfidCard -> Student -> create AttendanceLog row
    | }
    */
}