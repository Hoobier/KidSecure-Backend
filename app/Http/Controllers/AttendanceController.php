<?php
//AttendanceController.php
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
     * Flat, one-row-per-tap log. Each row: studentId, name, gradeLevel, section, timestamp, type ('in'|'out').
     * Optional filters: date, name (search by name OR studentId), gradeLevel, section.
     * Optional sort:  sort=name|studentId  +  dir=asc|desc
     * Optional pagination:  page=int  +  per_page=int
     *
     * Grade/section/name filters apply to Student first (Mongo has no native join),
     * then we narrow AttendanceLog by the resulting studentId list.
     */
    public function index(Request $request)
    {
        $hasStudentFilter = $request->filled('name')
            || $request->filled('gradeLevel')
            || $request->filled('section');

        // Lookup map for every student (not just filtered ones), so names/grades/sections
        // still render correctly on rows even when no student filter is active.
        $allStudents = Student::all(['studentId', 'firstName', 'lastName', 'gradeLevel', 'section']);
        $studentMetaById = $allStudents->mapWithKeys(function ($s) {
            return [$s->studentId => [
                'name' => trim($s->firstName . ' ' . $s->lastName),
                'gradeLevel' => $s->gradeLevel,
                'section' => $s->section,
            ]];
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
                $tokens = preg_split('/\s+/', trim((string) $request->query('name')), -1, PREG_SPLIT_NO_EMPTY);
                if (count($tokens) > 0) {
                    $studentQuery->where(function ($q) use ($tokens) {
                    foreach ($tokens as $tok) {
                        if ($tok === '') {
                            continue;
                        }
                        $q->where(function ($inner) use ($tok) {
                            $inner->where('firstName', 'like', "%{$tok}%")
                                  ->orWhere('lastName', 'like', "%{$tok}%")
                                  ->orWhere('studentId', 'like', "%{$tok}%");
                        });
                    }
                });
                }
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

        // Pagination params (same default format as StudentController@index)
        $perPage = max(1, (int) $request->query('per_page', 20));
        $currentPage = max(1, (int) $request->query('page', 1));

        // Default: newest first. Explicit sort overrides the default order.
        $sort = in_array($request->query('sort'), ['name', 'studentId'], true)
            ? $request->query('sort')
            : null;
        $dir = strtolower($request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sort) {
            // Sort after hydration so we can order by student fields not stored in AttendanceLog.
            // Since sorting requires the entire dataset, we hydrate everything then slice.
            $allLogs = $query->get();

            $sorted = $allLogs->sort(function ($a, $b) use ($sort, $dir, $studentMetaById) {
                if ($sort === 'studentId') {
                    $cmp = strcasecmp((string) $a->studentId, (string) $b->studentId);
                } else {
                    $nameA = $studentMetaById->get($a->studentId)['name'] ?? (string) $a->studentId;
                    $nameB = $studentMetaById->get($b->studentId)['name'] ?? (string) $b->studentId;
                    $cmp = strcasecmp($nameA, $nameB);
                }
                if ($cmp === 0) {
                    // Stable fallback: newest first when values tie
                    return strcmp((string) $b->timestamp, (string) $a->timestamp);
                }
                return $dir === 'desc' ? -$cmp : $cmp;
            })->values();

            $total = $sorted->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($currentPage > $lastPage) {
                $currentPage = $lastPage;
            }
            $offset = ($currentPage - 1) * $perPage;
            $pageSlice = $sorted->slice($offset, $perPage)->values();
            $from = $total > 0 ? $offset + 1 : 0;
            $to = $total > 0 ? min($offset + $perPage, $total) : 0;

            $logs = $pageSlice;
        } else {
            $total = $query->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($currentPage > $lastPage) {
                $currentPage = $lastPage;
            }
            $offset = ($currentPage - 1) * $perPage;
            $logs = $query
                ->orderBy('timestamp', 'desc')
                ->skip($offset)
                ->take($perPage)
                ->get();
            $from = $total > 0 ? $offset + 1 : 0;
            $to = $total > 0 ? min($offset + $perPage, $total) : 0;
        }

        $result = $logs->map(function ($log) use ($studentMetaById) {
            $meta = $studentMetaById->get($log->studentId, []);
            return [
                'studentId' => $log->studentId,
                'name' => $meta['name'] ?? $log->studentId,
                'gradeLevel' => $meta['gradeLevel'] ?? null,
                'section' => $meta['section'] ?? null,
                'timestamp' => Carbon::parse($log->timestamp)->toIso8601String(),
                'type' => $log->type,
            ];
        });

        $meta = [
            'current_page' => $currentPage,
            'from'         => $from,
            'to'           => $to,
            'total'        => $total,
            'per_page'     => $perPage,
            'last_page'    => $lastPage,
        ];

        return response()->json([
            'data' => $result->values(),
            'meta' => $meta,
        ]);
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