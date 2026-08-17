<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\ParentAccount;
use App\Models\AttendanceLog;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary
     *
     * Aggregate counts for the admin dashboard. "Today" is calculated in
     * Asia/Manila time and converted to UTC bounds, matching the same
     * pattern used in AttendanceController::index() for its date filter.
     *
     * "Late" is intentionally NOT included — there is no school start-time
     * setting anywhere in the system yet, so a "late" threshold would have
     * to be guessed. Only Present/Absent are shown until a real school-hours
     * setting exists.
     */
    public function summary()
    {
        $totalStudents = Student::count();
        $activeStudents = Student::where('enrollmentStatus', 'active')->get();
        $activeCount = $activeStudents->count();

        $totalParentAccounts = ParentAccount::count();

        $missingRfid = $activeStudents->filter(function ($s) {
            return empty($s->rfidTag);
        })->count();

        $missingParentLink = $activeStudents->filter(function ($s) {
            return empty($s->parentId);
        })->count();

        // ---- Today's attendance (Asia/Manila day, converted to UTC bounds) ----
        $start = Carbon::now('Asia/Manila')->startOfDay()->setTimezone('UTC');
        $end = Carbon::now('Asia/Manila')->endOfDay()->setTimezone('UTC');

        $todaysInLogs = AttendanceLog::whereBetween('timestamp', [$start, $end])
            ->where('type', 'in')
            ->get();

        $activeStudentIds = $activeStudents->pluck('studentId')->all();

        $presentStudentIds = $todaysInLogs
            ->pluck('studentId')
            ->unique()
            ->filter(fn ($id) => in_array($id, $activeStudentIds));

        $hasData = $presentStudentIds->count() > 0;
        $present = $presentStudentIds->count();
        $absent = $hasData ? max($activeCount - $present, 0) : 0;

        // ---- Attention items, structured so the frontend controls wording ----
        $attentionItems = [];

        if ($missingRfid > 0) {
            $attentionItems[] = ['type' => 'missing_rfid', 'count' => $missingRfid];
        }

        if ($missingParentLink > 0) {
            $attentionItems[] = ['type' => 'missing_parent', 'count' => $missingParentLink];
        }

        return response()->json([
            'totalStudents' => $totalStudents,
            'activeStudents' => $activeCount,
            'totalParentAccounts' => $totalParentAccounts,
            'missingRfid' => $missingRfid,
            'missingParentLink' => $missingParentLink,
            'todayAttendance' => [
                'hasData' => $hasData,
                'present' => $present,
                'absent' => $absent,
            ],
            'attentionItems' => $attentionItems,
        ]);
    }
}