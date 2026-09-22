<?php
// app/Console/Commands/DemoReset.php
namespace App\Console\Commands;

use App\Models\Student;
use App\Models\ParentAccount;
use App\Models\AttendanceLog;
use App\Models\AcademicRecord;
use App\Models\RfidCard;
use App\Models\ReportCardRevision;
use App\Models\EnrollmentApplication;
use App\Services\FirebaseService;
use App\Services\FirebaseRealtimeService;
use App\Services\DocumentUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Wipes all student-related data (including guest enrollments) for a fresh demo. Teachers and admin accounts are never touched.';

    public function handle(
        FirebaseService $firebase,
        FirebaseRealtimeService $realtime,
        DocumentUploadService $uploader
    ) {
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('Refusing to run in production without --force.');
            return 1;
        }

        if (!$this->option('force')) {
            $counts = [
                'students'                  => Student::count(),
                'parents'                   => ParentAccount::count(),
                'attendance logs'           => AttendanceLog::count(),
                'academic records'          => AcademicRecord::count(),
                'RFID cards'                => RfidCard::count(),
                'report card revisions'     => ReportCardRevision::count(),
                'guest enrollment apps'     => EnrollmentApplication::count(),
            ];

            $this->warn('This will PERMANENTLY delete:');
            foreach ($counts as $label => $n) {
                $this->line("  - {$n} {$label}");
            }
            $this->line('  - All Firebase Auth parent logins');
            $this->line('  - All Firebase Realtime Database entries');
            $this->line('  - All uploaded Cloudinary documents (students + applications)');
            $this->line('');
            $this->line('Teachers and admin/staff accounts will NOT be touched.');

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('Cancelled. No data was deleted.');
                return 0;
            }
        }

        $this->info('Starting reset...');

        // ==============================================================
        // STEP 1: Collect Firebase Auth UIDs before parents are deleted.
        // ==============================================================
        $firebaseUids = ParentAccount::whereNotNull('firebaseUid')
            ->pluck('firebaseUid')
            ->filter()
            ->values()
            ->all();
        $this->line('Step 1/7: Found ' . count($firebaseUids) . ' parent Firebase Auth account(s).');

        // ==============================================================
        // STEP 2: Delete Firebase Auth users.
        // ==============================================================
        $authDeleted = 0;
        $authFailed  = 0;
        foreach ($firebaseUids as $uid) {
            try {
                $firebase->getAuth()->deleteUser($uid);
                $authDeleted++;
            } catch (\Throwable $e) {
                $authFailed++;
                Log::warning("demo:reset — could not delete Firebase Auth user {$uid}: " . $e->getMessage());
            }
        }
        $this->line("Step 2/7: Removed {$authDeleted} Firebase Auth account(s)."
            . ($authFailed > 0 ? " ({$authFailed} failed — see log.)" : ''));

        // ==============================================================
        // STEP 3: Delete Cloudinary documents — students + applications.
        // ==============================================================
        $cloudDeleted = 0;
        $cloudFailed  = 0;

        $purge = function ($doc) use (&$cloudDeleted, &$cloudFailed, $uploader) {
            if (!is_array($doc) || empty($doc['public_id'])) return;
            try {
                $uploader->delete($doc['public_id'], $doc['resource_type'] ?? 'image');
                $cloudDeleted++;
            } catch (\Throwable $e) {
                $cloudFailed++;
                Log::warning("demo:reset — Cloudinary delete failed for {$doc['public_id']}: " . $e->getMessage());
            }
        };

        // Student documents — flat array of objects
        foreach (Student::all() as $student) {
            foreach (($student->documents ?? []) as $doc) {
                $purge($doc);
            }
        }

        // Enrollment application documents — keyed map { type: {...} }
        foreach (EnrollmentApplication::all() as $app) {
            foreach (($app->documents ?? []) as $doc) {
                $purge($doc);
            }
        }

        $this->line("Step 3/7: Deleted {$cloudDeleted} Cloudinary document(s)."
            . ($cloudFailed > 0 ? " ({$cloudFailed} failed — see log.)" : ''));

        // ==============================================================
        // STEP 4: Clear RTDB root nodes.
        // ==============================================================
        try {
            $realtime->clearAllData();
            $this->line('Step 4/7: Cleared Firebase Realtime Database.');
        } catch (\Throwable $e) {
            $this->error('Step 4/7: Failed to clear RTDB — ' . $e->getMessage());
            Log::error('demo:reset — RTDB clear failed: ' . $e->getMessage());
        }

        // ==============================================================
        // STEP 5: Wipe the Mongo collections.
        // ==============================================================
        $appsDeleted      = EnrollmentApplication::query()->delete();
        $studentsDeleted  = Student::query()->delete();
        $parentsDeleted   = ParentAccount::query()->delete();
        $logsDeleted      = AttendanceLog::query()->delete();
        $academicsDeleted = AcademicRecord::query()->delete();
        $rfidDeleted      = RfidCard::query()->delete();
        $revisionsDeleted = ReportCardRevision::query()->delete();

        $this->line("Step 5/7: Deleted from Mongo — "
            . "{$appsDeleted} guest applications, {$studentsDeleted} students, "
            . "{$parentsDeleted} parents, {$logsDeleted} attendance logs, "
            . "{$academicsDeleted} academic records, {$rfidDeleted} RFID cards, "
            . "{$revisionsDeleted} report card revisions.");

        // ==============================================================
        // STEP 6: Report any leftover draft cloud assets (informational).
        // ==============================================================
        // Drafts are session-scoped; no Mongo records exist for them.
        // Any leftover Cloudinary assets under kidsecure/enrollment-drafts
        // from abandoned sessions are not cleaned here (we have no list of
        // draftIds). They can be purged manually from the Cloudinary dashboard.
        $this->line('Step 6/7: Skipped draft cleanup (drafts are session-scoped, no Mongo records).');

        // ==============================================================
        // STEP 7: Summary.
        // ==============================================================
        $this->line('Step 7/7: Done.');
        $this->info('Reset complete. The next enrolled student will start again at ' . now()->year . '-0001.');

        return 0;
    }
}