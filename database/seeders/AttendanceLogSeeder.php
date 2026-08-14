<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\AttendanceLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceLogSeeder extends Seeder
{
    public function run(): void
    {
        // Assumes 'enrollmentStatus' uses the string "active" — matches the
        // "active" badge shown on your /students screen.
        $students = Student::where('enrollmentStatus', 'active')->get();

        if ($students->isEmpty()) {
            $this->command->warn('No active students found — skipping.');
            return;
        }

        AttendanceLog::truncate();

        $dates = [now()->subDay()->toDateString(), now()->toDateString()];

        foreach ($students as $student) {
            foreach ($dates as $date) {
                if (rand(0, 9) === 0) continue; // simulate an absence

                $timeIn = Carbon::parse($date)->setTime(rand(6, 8), rand(0, 59), rand(0, 59));

                AttendanceLog::create([
                    'studentId' => $student->studentId,
                    'rfidTag' => $student->rfidTag,
                    'type' => 'in',
                    'timestamp' => $timeIn,
                    'method' => 'rfid',
                ]);

                if (rand(0, 4) !== 0) {
                    $timeOut = Carbon::parse($date)->setTime(rand(15, 17), rand(0, 59), rand(0, 59));

                    AttendanceLog::create([
                        'studentId' => $student->studentId,
                        'rfidTag' => $student->rfidTag,
                        'type' => 'out',
                        'timestamp' => $timeOut,
                        'method' => 'rfid',
                    ]);
                }
            }
        }

        $this->command->info('Seeded attendance logs for ' . $students->count() . ' students.');
    }
}