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

Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [PasswordController::class, 'forgotPassword']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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
});

// Flutter parent app — Firebase ID token auth, completely separate
// from the admin portal's Sanctum routes above (sibling group, not nested).
Route::middleware('verify.firebase')->prefix('app')->group(function () {
    Route::get('/me', [ParentAppController::class, 'me']);
    Route::patch('/me', [ParentAppController::class, 'update']);
    Route::patch('/notifications', [ParentAppController::class, 'updateNotifications']);
    Route::post('/password/change', [PasswordController::class, 'changePassword']);
    
});

Route::middleware('verify.device')->prefix('device')->group(function () {
    Route::post('/scan', [DeviceScanController::class, 'scan']);
});