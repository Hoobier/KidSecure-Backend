<?php
// app/Http/Controllers/EnrollmentRfidController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EnrollmentRfidController extends Controller
{
    const LISTEN_KEY = 'rfid_enrollment_listen';

    /**
     * POST /api/enrollment/rfid/start-listening
     * Body (optional): { "excludeStudentId": "..." } — pass the student's
     * own Mongo _id when re-scanning their tag from the Edit page, so it
     * isn't flagged as a duplicate of itself.
     */
    public function startListening(Request $request)
    {
        Cache::put(self::LISTEN_KEY, [
            'active' => true,
            'excludeStudentId' => $request->input('excludeStudentId'),
            'result' => null,
        ], now()->addSeconds(30));

        return response()->json(['status' => 'listening']);
    }

    /**
     * GET /api/enrollment/rfid/pending-scan
     */
    public function pendingScan()
    {
        $listen = Cache::get(self::LISTEN_KEY);

        if (!$listen) {
            return response()->json(['status' => 'expired']);
        }

        if ($listen['active']) {
            return response()->json(['status' => 'waiting']);
        }

        return response()->json($listen['result'] ?? ['status' => 'expired']);
    }

    /**
     * POST /api/enrollment/rfid/stop-listening
     * Called when the admin cancels or navigates away mid-scan.
     */
    public function stopListening()
    {
        Cache::forget(self::LISTEN_KEY);
        return response()->json(['status' => 'stopped']);
    }
}