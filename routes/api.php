<?php
//routes/api.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ParentAppController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\DeviceScanController;
use App\Http\Controllers\EnrollmentRfidController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EnrollmentDraftDocumentController;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [PasswordController::class, 'forgotPassword']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/ping', function () {
    return response()->json(['status' => 'awake']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::post('/students/{id}/resend-credentials', [StudentController::class, 'resendCredentials']);
    Route::post('/students/{id}/deactivate', [StudentController::class, 'deactivate']);
    Route::get('/parents/search', [ParentController::class, 'search']);
    Route::patch('/students/{id}', [StudentController::class, 'update']);
    Route::get('/attendance-logs', [AttendanceController::class, 'index']);
    Route::get('/attendance-logs/filter-options', [AttendanceController::class, 'filterOptions']);
    Route::post('/students/{id}/reactivate', [StudentController::class, 'reactivate']);
    Route::get('/parents', [ParentController::class, 'index']);
    Route::get('/parents/{id}', [ParentController::class, 'show']);
    Route::patch('/parents/{id}', [ParentController::class, 'update']);
    Route::post('/parents/{id}/resend-credentials', [ParentController::class, 'resendCredentials']);
    Route::post('/students/{id}/reassign-rfid', [StudentController::class, 'reassignRfid']);
    Route::post('/students/{id}/reassign-parent', [StudentController::class, 'reassignParent']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::post('/students/{id}/delete', [StudentController::class, 'softDelete']);
    Route::post('/students/{id}/restore', [StudentController::class, 'restore']);
    Route::post('/enrollment/rfid/start-listening', [EnrollmentRfidController::class, 'startListening']);
    Route::get('/enrollment/rfid/pending-scan', [EnrollmentRfidController::class, 'pendingScan']);
    Route::post('/enrollment/rfid/stop-listening', [EnrollmentRfidController::class, 'stopListening']);
    Route::post('/students/{id}/documents', [DocumentController::class, 'store']);
    Route::get('/students/{id}/documents/{type}', [DocumentController::class, 'show']);
    Route::post('/enrollment-drafts/{draftId}/documents', [EnrollmentDraftDocumentController::class, 'store']);
    Route::get('/enrollment-drafts/{draftId}/documents/{type}', [EnrollmentDraftDocumentController::class, 'show']);
});

// Flutter parent app — Firebase ID token auth, completely separate
// from the admin portal's Sanctum routes above (sibling group, not nested).
Route::middleware('verify.firebase')->prefix('app')->group(function () {
    Route::get('/me', [ParentAppController::class, 'me']);
    Route::patch('/me', [ParentAppController::class, 'update']);
    Route::patch('/notifications', [ParentAppController::class, 'updateNotifications']);
    Route::post('/password/change', [PasswordController::class, 'changePassword']);
    Route::post('/parent/fcm-token', [ParentAppController::class, 'updateFcmToken']);
    
});

Route::middleware('verify.device')->prefix('device')->group(function () {
    Route::post('/scan', [DeviceScanController::class, 'scan']);
});
