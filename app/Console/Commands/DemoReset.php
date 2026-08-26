<?php
// app/Console/Commands/DemoReset.php
namespace App\Console\Commands;

use App\Models\Student;
use App\Models\ParentAccount;
use App\Models\AttendanceLog;
use App\Services\FirebaseService;
use App\Services\FirebaseRealtimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DemoReset extends Command
{
    /**
     * php artisan demo:reset          -> asks for confirmation first
     * php artisan demo:reset --force  -> skips the confirmation prompt
     */
    protected $signature = 'demo:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Deletes all students, parents, and attendance records for a fresh demo. Admin/staff accounts are never touched.';

    public function handle(FirebaseService $firebase, FirebaseRealtimeService $realtime)
    {
        // ---- Safety net: this command is destructive and irreversible ----
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('Refusing to run in production without --force. Double-check you meant to do this.');
            return 1;
        }

        if (!$this->option('force')) {
            $studentCount = Student::count();
            $parentCount  = ParentAccount::count();
            $logCount     = AttendanceLog::count();

            $this->warn("This will PERMANENTLY delete:");
            $this->line("  - {$studentCount} student record(s)");
            $this->line("  - {$parentCount} parent account(s) (including their mobile app login)");
            $this->line("  - {$logCount} attendance log(s)");
            $this->line('  - Matching Firebase Realtime Database entries');
            $this->line('');
            $this->line('Admin/staff (Sanctum) accounts will NOT be touched.');

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('Cancelled. No data was deleted.');
                return 0;
            }
        }

        $this->info('Starting reset...');

        // ================================================================
        // STEP 1: Collect Firebase Auth UIDs BEFORE we delete the Mongo
        // records that reference them. Once ParentAccount rows are gone,
        // there's no way to look these up again.
        // ================================================================
        $firebaseUids = ParentAccount::whereNotNull('firebaseUid')
            ->pluck('firebaseUid')
            ->filter()
            ->values()
            ->all();

        $this->line('Step 1/5: Found ' . count($firebaseUids) . ' parent login account(s) to remove from Firebase Auth.');

        // ================================================================
        // STEP 2: Delete the Firebase Auth users (parent mobile app
        // logins only). This is what actually frees up the email address
        // for reuse in Firebase — deleting ParentAccount alone does not.
        // ================================================================
        $authDeleted = 0;
        $authFailed = 0;

        foreach ($firebaseUids as $uid) {
            try {
                $firebase->getAuth()->deleteUser($uid);
                $authDeleted++;
            } catch (\Throwable $e) {
                // User may already be gone, or UID stale — don't let one
                // bad record stop the whole reset.
                $authFailed++;
                Log::warning("demo:reset — could not delete Firebase Auth user {$uid}: " . $e->getMessage());
            }
        }

        $this->line("Step 2/5: Removed {$authDeleted} Firebase Auth account(s)." . ($authFailed > 0 ? " ({$authFailed} could not be removed — see log.)" : ''));

        // ================================================================
        // STEP 3: Clear the three RTDB root nodes. Removing the root
        // reference wipes every child underneath it in one call — no need
        // to loop student-by-student.
        // ================================================================
        try {
            $realtime->clearAllData();
            $this->line('Step 3/5: Cleared Firebase Realtime Database (students, parents, entryExitLogs).');
        } catch (\Throwable $e) {
            $this->error('Step 3/5: Failed to clear Realtime Database — ' . $e->getMessage());
            Log::error('demo:reset — RTDB clear failed: ' . $e->getMessage());
        }

        // ================================================================
        // STEP 4: Wipe the Mongo collections. Order doesn't matter here
        // since we already pulled everything we needed in Step 1.
        // ================================================================
        $studentsDeleted = Student::query()->delete();
        $parentsDeleted  = ParentAccount::query()->delete();
        $logsDeleted     = AttendanceLog::query()->delete();

        $this->line("Step 4/5: Deleted {$studentsDeleted} student(s), {$parentsDeleted} parent(s), {$logsDeleted} attendance log(s) from the database.");

        // ================================================================
        // STEP 5: Summary. Student IDs reset automatically next time
        // someone enrolls, since StudentController computes the count
        // live rather than using a separate counter.
        // ================================================================
        $this->line('Step 5/5: Done.');
        $this->info('Reset complete. The next enrolled student will start again at ' . now()->year . '-0001.');

        return 0;
    }
}