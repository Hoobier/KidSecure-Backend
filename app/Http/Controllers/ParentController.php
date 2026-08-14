<?php

namespace App\Http\Controllers;

use App\Models\ParentAccount;
use App\Models\Student;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function search(Request $request)
    {
        $query = trim($request->query('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['parents' => []]);
        }

        $parents = ParentAccount::where(function ($q) use ($query) {
                $q->where('firstName', 'like', "%{$query}%")
                  ->orWhere('lastName', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get();

        $results = $parents->map(function ($parent) {
            return [
                'id' => (string) $parent->_id,
                'firstName' => $parent->firstName,
                'lastName' => $parent->lastName,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'studentCount' => count($parent->studentIds ?? []),
            ];
        });

        return response()->json(['parents' => $results]);
    }

    /**
     * GET /api/parents
     * Full parent directory listing for the admin portal's Parent Directory page.
     * Children are derived from Student.parentId (canonical link) rather than
     * ParentAccount.studentIds, since studentIds isn't touched by search() above
     * and its sync guarantees aren't confirmed.
     */
    public function index(Request $request)
    {
        $parents = ParentAccount::all();
        $students = Student::all(['studentId', 'firstName', 'lastName', 'parentId', 'enrollmentStatus']);

        $childrenByParent = [];
        foreach ($students as $s) {
            $childrenByParent[$s->parentId][] = [
                'id' => (string) $s->_id,
                'studentId' => $s->studentId,
                'name' => trim($s->firstName . ' ' . $s->lastName),
                'status' => $s->enrollmentStatus ?? 'active',
            ];
        }

        $result = $parents->map(function ($p) use ($childrenByParent) {
            return [
                'id' => (string) $p->_id,
                'name' => trim($p->firstName . ' ' . $p->lastName),
                'email' => $p->email,
                'phone' => $p->phone,
                'children' => $childrenByParent[(string) $p->_id] ?? [],
            ];
        });

        if ($request->filled('search')) {
            $needle = strtolower($request->query('search'));
            $result = $result->filter(function ($p) use ($needle) {
                return str_contains(strtolower($p['name']), $needle)
                    || str_contains(strtolower($p['email'] ?? ''), $needle);
            })->values();
        }

        return response()->json(['data' => $result]);
    }

    /**
     * POST /api/parents/{id}/resend-credentials
     * Resends mobile app login credentials directly from the parent's own ID,
     * rather than requiring a student ID like StudentController::resendCredentials()
     * does. Mirrors that method's Firebase/email logic.
     */
    public function resendCredentials($id)
    {
        $parent = ParentAccount::find($id);

        if (!$parent) {
            return response()->json(['message' => 'Parent account not found.'], 404);
        }

        // Grab one linked student's name for the email template — doesn't matter
        // which one if there are multiple children, the login is per-parent either way.
        $student = Student::where('parentId', $id)->first();
        $studentFullName = $student ? trim($student->firstName . ' ' . $student->lastName) : '';

        try {
            $firebase = app(\App\Services\FirebaseService::class);
            $result = $firebase->resetParentPassword($parent->email);

            $parentFullName = trim($parent->firstName . ' ' . $parent->lastName);

            \Illuminate\Support\Facades\Mail::to($parent->email)->send(
                new \App\Mail\ParentAccountCreated($parentFullName, $parent->email, $result['password'], $studentFullName)
            );

            return response()->json(['message' => 'Login information has been resent.']);
        } catch (\Throwable $e) {
            \Log::error("Resend credentials failed for {$parent->email}: " . $e->getMessage());
            return response()->json([
                'message' => 'Unable to resend login information. Please try again.',
            ], 500);
        }
    }
}